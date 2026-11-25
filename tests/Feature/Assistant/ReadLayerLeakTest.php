<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Reading\Fields;
use App\Domain\Assistant\Reading\RecordKinds;
use App\Domain\Assistant\Reading\RecordReader;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Models\Activity;
use App\Models\Asset;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomField;
use App\Models\DayRoute;
use App\Models\Document;
use App\Models\Event;
use App\Models\FreeformMaterial;
use App\Models\Image;
use App\Models\InternalAnnouncement;
use App\Models\LedgerAccount;
use App\Models\MaintenanceContract;
use App\Models\MaintenanceContractTemplate;
use App\Models\Material;
use App\Models\Permission;
use App\Models\PriceFormula;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTemplate;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteVersion;
use App\Models\Remark;
use App\Models\Role;
use App\Models\ServiceCheck;
use App\Models\ServiceCheckInstance;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMacro;
use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskCheck;
use App\Models\ServiceOrderTaskInstance;
use App\Models\ServiceOrderTaskInstanceCheck;
use App\Models\StandardAttachment;
use App\Models\StandardEmail;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserPlanGroup;
use App\Models\UserRole;
use App\Models\UserUnavailability;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The assistant reads exactly what the screens let somebody read, for every
 * readable kind at once, including kinds made readable after this was written.
 *
 * Held per kind, against the policies themselves rather than against what this
 * test thinks the rules are:
 *
 * - a kind without a visibility scope is refused without viewAny: no count, no
 *   record;
 * - a kind with one is read by anyone and the scope decides the rows, so the
 *   count and the record reader see the rows view allows and no others,
 *   compared on the rows made here, also for somebody without viewAny; and
 *   somebody without any rights at all gets no row of any kind;
 * - a field behind a permission is absent from a record without it, and a
 *   condition, sum or grouping on it is refused.
 *
 * Every readable kind gets rows, through its factory or through the record it
 * hangs off, and a kind that was never compared fails the run: a kind made
 * readable next month without a way to make one here is a kind nobody checked.
 * The people tried are an admin and one per permission that exists, so a
 * permission added next month is tried as well.
 */
class ReadLayerLeakTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    private const ROWS_READ_PER_KIND = 3;

    private const ROWS_COMPARED_PER_KIND = 50;

    private const SUPER_ADMIN_NAME = 'MajorLabel Beheer';

    /**
     * Who holds the right to every record of a scoped kind, so its scope hands the
     * query back untouched (RecordKinds::narrowsFor is false): a permission, or
     * MajorLabel's own account.
     */
    private const EVERY_RECORD = [
        Asset::class => 'asset.read',
        Customer::class => 'customer.read',
        DayRoute::class => 'event.see_all',
        Event::class => 'event.see_all',
        Product::class => 'product.read',
        Project::class => 'project.read',
        Role::class => '(super admin)',
        ServiceOrder::class => 'serviceorder.read',
        Ticket::class => 'ticket.see_all',
        User::class => '(super admin)',
    ];

    /** Scoped kinds no right opens whole: what they hang off decides every row. */
    private const ALWAYS_NARROWED = [
        Document::class,
        FreeformMaterial::class,
        Image::class,
        ProjectMilestone::class,
        Remark::class,
        ServiceCheckInstance::class,
        ServiceJob::class,
        ServiceOrderTaskInstance::class,
        ServiceOrderTaskInstanceCheck::class,
        UserUnavailability::class,
    ];

    /** @var array<string, User>|null */
    private ?array $people = null;

    private ?User $super_admin = null;

    private bool $seeded = false;

    private function read(User $user, array $arguments): ToolResult
    {
        return app(ToolExecutor::class)->run(new ToolCall('read_records', $arguments, $user));
    }

    /**
     * Nobody with nothing, an admin, and one person per permission.
     *
     * @return array<string, User>
     */
    private function people(): array
    {
        if ($this->people !== null) {
            return $this->people;
        }

        $people = ['(geen rechten)' => $this->userWithPermissions(), '(admin)' => $this->admin()];

        foreach (Permission::orderBy('name')->pluck('name') as $name) {
            $people[$name] = $this->userWith($name);
        }

        return $this->people = $people;
    }

    /** @param  class-string<Model>  $class */
    private function make(string $class, array $attributes): Model
    {
        return $class::forceCreate($attributes);
    }

    /**
     * Rows of every readable kind: through the factories where there are any,
     * and through the record they hang off where there are not. Nothing is
     * swallowed: a recipe that stops working fails here, not by leaving a kind
     * unchecked.
     */
    private function seedEveryKind(): void
    {
        if ($this->seeded) {
            return;
        }

        $this->seeded = true;

        $this->super_admin = User::factory()->create(['name' => self::SUPER_ADMIN_NAME]);
        $this->super_admin->roles()->attach(Role::firstOrCreate(['name' => Role::SUPERADMIN])->id);

        /** The factories pick an existing customer, product, machine or werkbon at random, so those go first. */
        foreach ([Customer::class, Product::class, Asset::class, ServiceOrder::class] as $parent) {
            $parent::factory()->count(2)->create();
        }

        $order = ServiceOrder::query()->firstOrFail();
        $job = ServiceJob::factory()->create(['service_order_id' => $order->id]);

        /** What a factory leaves to the record it hangs off. */
        $parents = [
            ServiceJob::class => ['service_order_id' => $order->id],
            ServiceCheckInstance::class => ['service_job_id' => $job->id, 'service_check_id' => ServiceCheck::factory()->create()->id],
        ];

        foreach (array_keys(RecordKinds::readable()) as $class) {
            if (method_exists($class, 'factory') && class_exists(Factory::resolveFactoryName($class)) && !$class::query()->exists()) {
                $class::factory()->count(2)->create($parents[$class] ?? []);
            }
        }

        $customer = Customer::query()->firstOrFail();
        $user = User::factory()->create();

        $project = $this->make(Project::class, ['title' => 'Nieuwbouw', 'customer_id' => $customer->id, 'project_manager_id' => $user->id]);
        $this->make(ProjectMilestone::class, ['project_id' => $project->id, 'title' => 'Oplevering']);
        $this->make(ProjectTemplate::class, ['name' => 'Standaardproject']);

        $quote = $this->make(Quote::class, ['number' => 'OFF-1', 'title' => 'Airco', 'customer_id' => $customer->id, 'quote_date' => '2026-11-01']);
        $this->make(QuoteLine::class, ['quote_id' => $quote->id, 'kind' => 'free', 'description' => 'Montage']);
        $this->make(QuoteVersion::class, ['quote_id' => $quote->id, 'number' => 1, 'snapshot' => []]);

        $task = $this->make(ServiceOrderTaskInstance::class, ['service_order_id' => $order->id, 'description' => 'Taak']);
        $this->make(ServiceOrderTaskInstanceCheck::class, ['service_order_task_instance_id' => $task->id, 'title' => 'Gecontroleerd']);
        $this->make(ServiceOrderTask::class, ['title' => 'Filter vervangen']);
        $this->make(ServiceOrderTaskCheck::class, ['title' => 'Filter schoon']);
        $this->make(ServiceOrderMacro::class, ['title' => 'Afsluiten', 'definition' => []]);

        $material = $this->make(FreeformMaterial::class, ['quantity' => 1, 'description' => 'Kabel', 'freeformmateriable_type' => ServiceOrder::class, 'freeformmateriable_id' => $order->id]);
        $this->assertNotNull($material->getKey());

        $order->images()->attach($this->make(Image::class, ['name' => 'foto.jpg', 'path' => 'images/foto.jpg'])->id);
        $order->documents()->attach($this->make(Document::class, ['name' => 'bon.pdf', 'path' => 'documents/bon.pdf'])->id);
        $order->remarks()->attach($this->make(Remark::class, ['content' => 'Sleutel bij de buren'])->id);

        $this->make(DayRoute::class, ['user_id' => $user->id, 'date' => '2026-11-25', 'stops' => [], 'finish' => '17:00', 'links' => []]);
        $this->make(UserUnavailability::class, ['user_id' => $user->id, 'type' => 'holiday', 'date' => '2026-12-24']);

        $this->make(PriceFormula::class, ['name' => 'Staffel']);
        $this->make(ProductAttribute::class, ['name' => 'Vermogen']);
        $this->make(Material::class, ['name' => 'Koperleiding']);
        $this->make(LedgerAccount::class, ['name' => 'Omzet']);
        $this->make(Supplier::class, ['name' => 'Groothandel']);
        $this->make(MaintenanceContractTemplate::class, ['name' => 'Jaarlijks']);
        $this->make(StandardEmail::class, ['name' => 'Bevestiging', 'subject' => 'Uw afspraak', 'body' => 'Tot dan']);
        $this->make(StandardAttachment::class, ['name' => 'Voorwaarden', 'path' => 'attachments/voorwaarden.pdf', 'original_filename' => 'voorwaarden.pdf', 'mime_type' => 'application/pdf', 'size' => 1]);
        $this->make(UserPlanGroup::class, ['name' => 'Ploeg noord']);
        $this->make(UserRole::class, ['name' => 'Elektricien']);
        $this->make(Contact::class, ['first_name' => 'Jan', 'last_name' => 'Jansen']);
        $this->make(InternalAnnouncement::class, ['title' => 'Nieuw', 'body' => 'Er is iets nieuws']);
        $this->make(CustomField::class, ['name' => 'Kleur', 'model_type' => Asset::class]);

        $missing = array_filter(array_keys(RecordKinds::readable()), fn (string $class) => !$class::query()->exists());

        $this->assertSame([], array_values(array_map(RecordKinds::noun(...), $missing)), 'no row was made of these kinds; give them a factory or a line in seedEveryKind');
    }

    /** The rows made here, newest first, including MajorLabel's account (nobody is signed in, so nothing hides it). */
    private function rowsOf(string $class): Collection
    {
        $this->seedEveryKind();

        $model = new $class;

        return $class::query()->orderByDesc($model->getKeyName())->limit(self::ROWS_COMPARED_PER_KIND)->get();
    }

    /** The count read_records gives over exactly these rows. */
    private function countOver(User $user, string $noun, Collection $rows): ToolResult
    {
        return $this->read($user, ['kind' => $noun, 'where' => [
            ['field' => $rows->first()->getKeyName(), 'op' => 'in', 'value' => $rows->modelKeys()],
        ]]);
    }

    /** What the screens let this person open: the policy's view, or viewAny where the policy has no view. */
    private function mayView(User $user, Model $record): bool
    {
        $policy = Gate::getPolicyFor($record);

        return method_exists($policy, 'view') ? $user->can('view', $record) : $user->can('viewAny', $record::class);
    }

    public function test_a_kind_without_a_scope_is_refused_to_somebody_without_view_any(): void
    {
        $leaks = [];

        foreach (RecordKinds::readable() as $class => $noun) {
            if (RecordKinds::hasVisibilityScope($class)) {
                continue;
            }

            $any = $this->rowsOf($class)->first();

            foreach ($this->people() as $permission => $user) {
                if ($user->can('viewAny', $class)) {
                    continue;
                }

                if (!$this->read($user, ['kind' => $noun])->is_error) {
                    $leaks[] = $noun . ' counted for ' . $permission;
                }

                if (app(RecordReader::class)->read($class, $any->getKey(), $user) !== null) {
                    $leaks[] = $noun . ' #' . $any->getKey() . ' read by ' . $permission;
                }
            }
        }

        $this->assertSame([], $leaks, implode('; ', $leaks));
    }

    /**
     * A kind with a scope is open to anyone, which is only safe while every scope
     * gives nothing to somebody with no rights at all: no count, no record, no
     * history of any of the rows made here.
     */
    public function test_somebody_without_any_rights_reads_no_row_of_any_kind(): void
    {
        $nobody = $this->people()['(geen rechten)'];
        $leaks = [];

        foreach (RecordKinds::readable() as $class => $noun) {
            $rows = $this->rowsOf($class);
            $counted = $this->read($nobody, ['kind' => $noun]);

            if (!$counted->is_error && $counted->content['aantal'] !== 0) {
                $leaks[] = $noun . ' counted ' . $counted->content['aantal'];
            }

            foreach ($rows->take(self::ROWS_READ_PER_KIND) as $row) {
                if (app(RecordReader::class)->read($class, $row->getKey(), $nobody) !== null) {
                    $leaks[] = $noun . ' #' . $row->getKey() . ' read';
                }

                if (method_exists($row, 'activities')) {
                    $row->activities()->attach(Activity::create([
                        'description' => 'Geschiedenis van ' . $noun . ' #' . $row->getKey(),
                        'category' => 'update',
                        'event_key' => 'test.updated',
                        'actor_type' => 'user',
                        'actor_name' => 'Iemand',
                        'occurred_at' => now(),
                    ])->id);
                }
            }
        }

        $history = app(ToolExecutor::class)->run(new ToolCall('search_activity', [], $nobody));

        foreach ($history->content['activities'] ?? [] as $entry) {
            $leaks[] = 'history: ' . $entry['description'];
        }

        $this->assertSame([], $leaks, implode('; ', $leaks));
    }

    public function test_only_the_records_view_allows_are_counted_or_read(): void
    {
        $wrong = [];
        $compared = [];

        foreach (RecordKinds::readable() as $class => $noun) {
            $rows = $this->rowsOf($class);

            foreach ($this->people() as $permission => $user) {
                if (!RecordKinds::mayRead($class, $user)) {
                    continue;
                }

                $visible = $rows->filter(fn (Model $row) => $this->mayView($user, $row));
                $result = $this->countOver($user, $noun, $rows);

                if ($result->is_error) {
                    $wrong[] = $noun . ' refused to ' . $permission . ': ' . $result->toModelContent();

                    continue;
                }

                $compared[$noun] = true;

                if ($result->content['aantal'] !== $visible->count()) {
                    $wrong[] = $noun . ' for ' . $permission . ': counted ' . $result->content['aantal']
                        . ' of ' . $rows->count() . ', view allows ' . $visible->count();
                }

                foreach ($rows->take(self::ROWS_READ_PER_KIND) as $row) {
                    $read = app(RecordReader::class)->read($class, $row->getKey(), $user) !== null;

                    if ($read !== $visible->contains($row)) {
                        $wrong[] = $noun . ' #' . $row->getKey() . ' for ' . $permission . ': '
                            . ($read ? 'read although view refuses it' : 'refused although view allows it');
                    }
                }
            }
        }

        $this->assertSame([], $wrong, implode('; ', $wrong));

        $never = array_values(array_diff(RecordKinds::readable(), array_keys($compared)));

        $this->assertSame([], $never, 'never compared, as nobody tried may read them: ' . implode(', ', $never));
    }

    /**
     * narrowsFor judges a scope by whether it adds a condition, and the history
     * search trusts it to skip the scope. So per scoped kind: whoever holds the
     * right to every record gets false, somebody without any rights true, a kind
     * no right opens whole true for everyone, and whoever gets false is handed
     * every row made here. A scope that narrows some other way fails here.
     */
    public function test_a_scope_narrows_exactly_when_narrows_for_says_so(): void
    {
        $this->seedEveryKind();
        $nobody = $this->people()['(geen rechten)'];
        $wrong = [];

        foreach (RecordKinds::readable() as $class => $noun) {
            if (!RecordKinds::hasVisibilityScope($class)) {
                continue;
            }

            $all = isset(self::EVERY_RECORD[$class]);

            if (!$all && !in_array($class, self::ALWAYS_NARROWED, true)) {
                $wrong[] = $noun . ' has a scope but is in neither EVERY_RECORD nor ALWAYS_NARROWED';

                continue;
            }

            if (!RecordKinds::narrowsFor($class, $nobody)) {
                $wrong[] = $noun . ' is not narrowed for somebody without any rights';
            }

            if ($all) {
                $holder = self::EVERY_RECORD[$class] === '(super admin)' ? $this->super_admin : $this->people()[self::EVERY_RECORD[$class]];

                if (RecordKinds::narrowsFor($class, $holder)) {
                    $wrong[] = $noun . ' is narrowed for ' . self::EVERY_RECORD[$class];
                }
            }

            foreach ($this->people() as $permission => $user) {
                $narrowed = RecordKinds::narrowsFor($class, $user);

                if (!$all && !$narrowed) {
                    $wrong[] = $noun . ' is not narrowed for ' . $permission . '; it belongs in EVERY_RECORD now';
                }

                if (!$narrowed && $class::query()->visibleTo($user)->count() < $class::query()->count()) {
                    $wrong[] = $noun . ' is said not to narrow for ' . $permission . ', yet keeps rows from them';
                }
            }
        }

        $this->assertSame([], $wrong, implode('; ', $wrong));
    }

    public function test_a_field_behind_a_permission_is_absent_and_cannot_be_asked_about_without_it(): void
    {
        $leaks = [];
        $tried = [];

        foreach (RecordKinds::readable() as $class => $noun) {
            $fields = new Fields($class, $this->admin());
            $gated = array_intersect_key($fields->gated(), $fields->columns());

            if ($gated === []) {
                continue;
            }

            $rows = $this->rowsOf($class);

            foreach ($gated as $column => $needed) {
                foreach ($this->people() as $permission => $user) {
                    if (!$user->can('viewAny', $class) || $user->hasPermission($needed)) {
                        continue;
                    }

                    $tried[$noun . '.' . $column] = true;

                    foreach ($rows->take(self::ROWS_READ_PER_KIND) as $row) {
                        $record = app(RecordReader::class)->read($class, $row->getKey(), $user);

                        if ($record !== null && array_key_exists($column, $record['velden'])) {
                            $leaks[] = $noun . '.' . $column . ' read by ' . $permission;
                        }
                    }

                    $asked = [
                        'where' => ['kind' => $noun, 'where' => [['field' => $column, 'op' => 'filled']]],
                        'sum' => ['kind' => $noun, 'sum' => $column],
                        'group' => ['kind' => $noun, 'group' => $column],
                    ];

                    foreach ($asked as $how => $arguments) {
                        if (!$this->read($user, $arguments)->is_error) {
                            $leaks[] = $noun . '.' . $column . ' answered by ' . $how . ' for ' . $permission;
                        }
                    }
                }
            }
        }

        $this->assertSame([], $leaks, implode('; ', $leaks));
        $this->assertArrayHasKey('klant.iban', $tried, 'nobody without customer.see_sensitive was tried');
    }

    public function test_a_hidden_field_is_never_read_not_even_by_an_admin(): void
    {
        $admin = $this->admin();
        $leaks = [];

        foreach (RecordKinds::readable() as $class => $noun) {
            $fields = new Fields($class, $admin);
            $hidden = array_diff((new $class)->getHidden(), array_keys($fields->gated()));

            if ($hidden === []) {
                continue;
            }

            $record = app(RecordReader::class)->read($class, $this->rowsOf($class)->first()->getKey(), $admin);

            foreach ($hidden as $column) {
                if ($record !== null && array_key_exists($column, $record['velden'])) {
                    $leaks[] = $noun . '.' . $column;
                }
            }
        }

        $this->assertSame([], $leaks, 'hidden yet read: ' . implode(', ', $leaks));
    }

    /** MajorLabel's account and role are as invisible to a count or a grouping as they are on every screen. */
    public function test_the_super_admin_is_neither_counted_nor_grouped(): void
    {
        $this->seedEveryKind();
        $colleague = User::factory()->create(['name' => 'Collega']);
        $their_role = Role::where('name', Role::SUPERADMIN)->firstOrFail();
        $other_role = Role::firstOrCreate(['name' => 'admin']);
        $checked = 0;

        foreach ($this->people() as $permission => $user) {
            if ($user->can('viewAny', User::class)) {
                $checked++;
                $ids = [$this->super_admin->id, $colleague->id];
                $where = [['field' => 'id', 'op' => 'in', 'value' => $ids]];

                $count = $this->read($user, ['kind' => 'medewerker', 'where' => $where]);
                $this->assertFalse($count->is_error, $count->toModelContent());
                $this->assertSame(1, $count->content['aantal'], 'the super admin was counted for ' . $permission);

                $grouped = $this->read($user, ['kind' => 'medewerker', 'where' => $where, 'group' => 'name']);
                $this->assertFalse($grouped->is_error, $grouped->toModelContent());
                $this->assertSame(['Collega'], array_column($grouped->content['groepen'], 'name'), 'the super admin was grouped for ' . $permission);

                $this->assertNull(app(RecordReader::class)->read(User::class, $this->super_admin->id, $user));
            }

            if ($user->can('viewAny', Role::class)) {
                $checked++;
                $where = [['field' => 'id', 'op' => 'in', 'value' => [$their_role->id, $other_role->id]]];

                $count = $this->read($user, ['kind' => 'rol', 'where' => $where]);
                $this->assertFalse($count->is_error, $count->toModelContent());
                $this->assertSame(1, $count->content['aantal'], 'the super admin role was counted for ' . $permission);

                $grouped = $this->read($user, ['kind' => 'rol', 'where' => $where, 'group' => 'name']);
                $this->assertNotContains(Role::SUPERADMIN, array_column($grouped->content['groepen'] ?? [], 'name'), 'the super admin role was grouped for ' . $permission);
            }
        }

        $this->assertGreaterThan(0, $checked, 'nobody tried may read medewerkers or rollen');
    }

    /**
     * The generic checks above agree view with the scope; this one makes sure the
     * scope actually narrows, with a monteur who executes one of two werkbonnen.
     */
    public function test_a_technician_counts_and_reads_only_his_own_werkbonnen(): void
    {
        $technician = $this->userWith('serviceorder.read_own');
        $customer = Customer::factory()->create();
        $mine = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $mine->syncExecutingUsers([$technician->id]);
        $other = ServiceOrder::factory()->create(['customer_id' => $customer->id]);

        $this->assertTrue(RecordKinds::mayRead(ServiceOrder::class, $technician), 'werkbon is not readable for serviceorder.read_own');

        $result = $this->read($technician, ['kind' => 'werkbon', 'where' => [['field' => 'customer_id', 'op' => '=', 'value' => $customer->id]]]);

        $this->assertFalse($result->is_error, $result->toModelContent());
        $this->assertSame(1, $result->content['aantal']);
        $this->assertNotNull(app(RecordReader::class)->read(ServiceOrder::class, $mine->id, $technician));
        $this->assertNull(app(RecordReader::class)->read(ServiceOrder::class, $other->id, $technician));
    }

    /** Through a relation, the related kind's own visibility holds: a has, a has-none and a field on it. */
    public function test_a_technician_reaches_through_a_relation_only_the_werkbonnen_he_may_see(): void
    {
        $technician = $this->userWithPermissions('serviceorder.read_own', 'customer.read');
        $his = Customer::factory()->create();
        $someone_elses = Customer::factory()->create();
        ServiceOrder::factory()->create(['customer_id' => $his->id, 'description' => 'eigen'])->syncExecutingUsers([$technician->id]);
        ServiceOrder::factory()->create(['customer_id' => $someone_elses->id, 'description' => 'geheim']);
        $both = ['field' => 'id', 'op' => 'in', 'value' => [$his->id, $someone_elses->id]];

        $count = fn (User $user, array $condition) => $this->read($user, ['kind' => 'klant', 'where' => [$both, $condition]])->content['aantal'] ?? null;

        $this->assertSame(1, $count($technician, ['has' => 'serviceOrders']));
        $this->assertSame(1, $count($technician, ['has' => 'serviceOrders', 'not' => 'ja']));
        $this->assertSame(0, $count($technician, ['field' => 'serviceOrders.description', 'op' => '=', 'value' => 'geheim']));
        $this->assertSame(1, $count($technician, ['field' => 'serviceOrders.description', 'op' => '=', 'value' => 'eigen']));
        $this->assertSame(1, $count($this->admin(), ['field' => 'serviceOrders.description', 'op' => '=', 'value' => 'geheim']));
    }

    /** A grouping on a foreign key names the records only for somebody who may read their kind. */
    public function test_group_names_are_withheld_for_a_kind_the_reader_may_not_read(): void
    {
        $customer = Customer::factory()->create(['name' => 'Naamloos BV']);
        ServiceOrder::factory()->count(2)->create(['customer_id' => $customer->id]);
        $arguments = ['kind' => 'werkbon', 'where' => [['field' => 'customer_id', 'op' => '=', 'value' => $customer->id]], 'group' => 'customer_id'];

        $without = $this->read($this->userWith('serviceorder.read'), $arguments);
        $this->assertFalse($without->is_error, $without->toModelContent());
        $this->assertSame([['customer_id' => $customer->id, 'aantal' => 2]], $without->content['groepen']);

        $with = $this->read($this->userWithPermissions('serviceorder.read', 'customer.read'), $arguments);
        $this->assertSame('Naamloos BV', $with->content['groepen'][0]['naam'] ?? null);
    }

    /** A gated column is as closed through a relation as it is on its own record. */
    public function test_a_gated_field_cannot_be_reached_through_a_relation(): void
    {
        $user = $this->userWithPermissions('serviceorder.read', 'customer.read');
        Customer::factory()->create(['iban' => 'NL91ABNA0417164300']);

        $result = $this->read($user, ['kind' => 'werkbon', 'where' => [['field' => 'customer.iban', 'op' => 'filled']]]);

        $this->assertTrue($result->is_error, 'a werkbon count filtered on the klant\'s iban came back: ' . $result->toModelContent());
    }

    /**
     * A monteur who sees machines only through his own werkbonnen counts the
     * contracts that hold one, and opens the list of them: the machine scope runs
     * inside the contract's pivot join, where a bare "id" would be ambiguous.
     */
    public function test_relevant_machines_count_contracts_through_their_pivot_and_open_the_list(): void
    {
        $technician = $this->userWithPermissions('asset.read.relevant.serviceorder', 'maintenancecontract.read');
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $his_machine = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id]);
        $other_machine = Asset::factory()->create(['customer_id' => $customer->id, 'product_id' => $product->id]);

        $order = ServiceOrder::factory()->create(['customer_id' => $customer->id]);
        $order->syncExecutingUsers([$technician->id]);
        ServiceJob::factory()->create(['service_order_id' => $order->id, 'asset_id' => $his_machine->id]);

        $with = MaintenanceContract::factory()->create(['customer_id' => $customer->id]);
        $with->assets()->attach($his_machine->id);
        $without = MaintenanceContract::factory()->create(['customer_id' => $customer->id]);
        $without->assets()->attach($other_machine->id);

        $result = $this->read($technician, ['kind' => 'onderhoudscontract', 'link' => true, 'where' => [
            ['field' => 'id', 'op' => 'in', 'value' => [$with->id, $without->id]],
            ['has' => 'assets'],
        ]]);

        $this->assertFalse($result->is_error, $result->toModelContent());
        $this->assertSame(1, $result->content['aantal']);

        $props = $this->actingAs($technician)->get($result->content['link'])->assertOk()->viewData('page')['props'];
        $ids = collect($props['maintenanceContracts']['data'])->pluck('id');

        $this->assertTrue($ids->contains($with->id));
        $this->assertFalse($ids->contains($without->id));
    }
}
