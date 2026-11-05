<?php

namespace App\Console\Commands;

use App\Exceptions\Refusal;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantSuperAdmins;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;

/**
 * What tenants:migrate cannot do for a dedicated installation imported here.
 *
 * The migrations bring its tables up to date, but a new customer gets more than
 * tables: the seeding gives it its stages and roles, and the application has
 * since started to reason with things the old installation never wrote down.
 * An installation from before the stages has open work orders without one, and
 * those never reach the planner. One from before 'plannable' has nobody to plan,
 * so the planner is empty. Rights that did not exist yet were never granted, so
 * the technicians lose whatever is now behind one.
 *
 * Runs twice. With --record before the migrations, to write down what the old
 * installation had: afterwards it can no longer be told which tables, columns
 * and rights are new. Without it after them, to fill in the rest. What depends
 * on that record is skipped when there is none, and says so.
 *
 * Changes rows directly, like the data migrations do, and raises no signals:
 * this converts how a fact is stored, it is not something that happened to the
 * record. Fifteen work orders moving stage would otherwise each write a timeline
 * entry and ring a bell for their technician on the first day.
 *
 * Safe to run again: everything it does looks first whether it is still needed.
 */
class AdaptImportedTenant extends Command
{
    protected $signature = 'tenant:adapt-import
        {database : the customer database, as in tenants:list}
        {--record : before the migrations: write down what the old installation had}
        {--super-admin=* : an address in that database that is one of our own accounts}
        {--dry-run : say what would change, change nothing}';

    protected $description = 'Brings an imported installation in line with what a new customer here is given';

    private const FLAGS = [
        'is_plannable_state', 'is_planned_state', 'is_closed_state',
        'is_planning_cancelled_state', 'is_invoiced_state', 'is_incomplete_state',
    ];

    /**
     * Never granted on the customer's behalf. Following where an employee is
     * during working hours is the employer's decision, and one they have to
     * announce to the people concerned; a new customer is handed it as a
     * default, an existing one has to choose it.
     */
    private const LEFT_TO_THE_CUSTOMER = ['location.track'];

    private bool $dry = false;

    public function handle(TenantSuperAdmins $super_admins): int
    {
        $database = $this->argument('database');
        $tenant = Tenant::on('central')->where('data->tenancy_db_name', $database)->first();

        if (!$tenant) {
            $this->error('No customer is registered on ' . $database . '.');

            return self::FAILURE;
        }

        $this->dry = (bool) $this->option('dry-run');

        if ($this->option('record')) {
            return $this->record($tenant);
        }

        $before = $this->recorded();

        $this->info(($this->dry ? 'Would adapt ' : 'Adapting ') . $tenant->name . ':');

        if ($before === null) {
            $this->warn('  No record of the old installation (run with --record before the migrations).');
            $this->warn('  Skipped: stages for old work orders, closing dates, the planner, new rights.');
        }

        if (!$this->adoptSuperAdmins($tenant, $super_admins)) {
            return self::FAILURE;
        }

        Tenancy::within($tenant, function () use ($before) {
            $this->jsonColumns();

            DB::beginTransaction();

            try {
                $this->stages();

                if ($before !== null) {
                    $this->ordersWithoutStage($before);
                    $this->closingDates();
                    $this->plannableUsers($before);
                    $this->newRights($before);
                }

                $this->missingRoles();
                $this->seats();

                $this->dry ? DB::rollBack() : DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();

                throw $e;
            }
        });

        if (!$this->dry && $before !== null) {
            File::delete($this->recordFile());
        }

        return self::SUCCESS;
    }

    private function recordFile(): string
    {
        return storage_path('app/imports/' . $this->argument('database') . '.before-migrations.txt');
    }

    /**
     * The tables, columns and rights of the old installation, one per line, as
     * the database stood straight after it was restored here.
     */
    private function record(Tenant $tenant): int
    {
        $lines = Tenancy::within($tenant, function () {
            $columns = collect(DB::select(
                'SELECT table_name AS t, column_name AS c
                FROM information_schema.columns WHERE table_schema = DATABASE()'
            ))->map(fn ($row) => 'column ' . $row->t . '.' . $row->c);

            $rights = DB::getSchemaBuilder()->hasTable('permissions')
                ? DB::table('permissions')->pluck('name')->map(fn ($name) => 'permission ' . $name)
                : collect();

            return $columns->merge($rights)->sort()->values();
        });

        File::ensureDirectoryExists(dirname($this->recordFile()));
        File::put($this->recordFile(), $lines->implode("\n") . "\n");

        $this->info('Recorded the old installation of ' . $tenant->name . ': '
            . $lines->filter(fn ($l) => str_starts_with($l, 'column '))->count() . ' columns, '
            . $lines->filter(fn ($l) => str_starts_with($l, 'permission '))->count() . ' rights.');

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     tables: Collection<int, string>,
     *     columns: Collection<int, string>,
     *     rights: Collection<int, string>
     * }|null
     */
    private function recorded(): ?array
    {
        if (!File::exists($this->recordFile())) {
            return null;
        }

        $lines = collect(file($this->recordFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

        $columns = $lines->filter(fn ($l) => str_starts_with($l, 'column '))
            ->map(fn ($l) => substr($l, 7))->values();

        return [
            'tables' => $columns->map(fn ($c) => strstr($c, '.', true))->unique()->values(),
            'columns' => $columns,
            'rights' => $lines->filter(fn ($l) => str_starts_with($l, 'permission '))
                ->map(fn ($l) => substr($l, 11))->values(),
        ];
    }

    /**
     * Our own account from the old installation, made a super admin here. The
     * import script has already moved it to an address that is free.
     */
    private function adoptSuperAdmins(Tenant $tenant, TenantSuperAdmins $super_admins): bool
    {
        foreach (array_filter($this->option('super-admin')) as $email) {
            if ($this->dry) {
                $this->line('  ours:        ' . $email . ' would become a super admin');

                continue;
            }

            try {
                $super_admins->adopt($tenant, $email);
            } catch (Refusal $e) {
                $this->error('  ' . $e->getMessage());

                return false;
            }

            $this->line('  ours:        ' . $email . ' is a super admin of this customer, with the password it had');
        }

        return true;
    }

    /**
     * A MariaDB dump restored into MySQL. MariaDB has no JSON type: its json
     * column is longtext with a json_valid() check, and MySQL takes that over as
     * just that. Here the same migration makes a real JSON column.
     */
    private function jsonColumns(): void
    {
        $server = (string) DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        if (DB::connection()->getDriverName() !== 'mysql' || stripos($server, 'mariadb') !== false) {
            return;
        }

        $checks = DB::select(
            "SELECT tc.table_name AS t, cc.constraint_name AS name, cc.check_clause AS clause
            FROM information_schema.check_constraints cc
            JOIN information_schema.table_constraints tc
                ON tc.constraint_schema = cc.constraint_schema AND tc.constraint_name = cc.constraint_name
            WHERE cc.constraint_schema = DATABASE() AND tc.constraint_type = 'CHECK'
                AND cc.check_clause LIKE 'json_valid(%'"
        );

        foreach ($checks as $check) {
            if (!preg_match('/^json_valid\(`([^`]+)`\)$/', $check->clause, $match)) {
                continue;
            }

            $column = DB::selectOne(
                'SELECT is_nullable AS nullable, column_default AS fallback, data_type AS type
                FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$check->t, $match[1]]
            );

            if (!$column || $column->fallback !== null) {
                $this->warn('  json:        ' . $check->t . '.' . $match[1] . ' left as it is: it has a default');

                continue;
            }

            if (!$this->dry) {
                DB::statement('ALTER TABLE `' . $check->t . '` DROP CHECK `' . $check->name . '`');
                DB::statement('ALTER TABLE `' . $check->t . '` MODIFY `' . $match[1] . '` JSON '
                    . ($column->nullable === 'YES' ? 'NULL' : 'NOT NULL'));
            }

            $this->line('  json:        ' . $check->t . '.' . $match[1] . ' ' . $column->type . ' -> json');
        }
    }

    /** @param  object|array<string, mixed>  $stage */
    private function profile(object|array $stage): string
    {
        $stage = (array) $stage;

        return collect(self::FLAGS)->map(fn ($flag) => (int) (bool) ($stage[$flag] ?? false))->implode('');
    }

    /**
     * A stage for every flag the application reasons with, as a new customer
     * gets them. A stage the old installation already had for a flag stays the
     * one -- the migrations make "Afgerond" for the closed work orders, and a
     * second closed stage beside it would only be a choice nobody can explain.
     */
    private function stages(): void
    {
        $standard = include base_path('database/seeders/data/tenant_stages.php');
        $existing = DB::table('service_order_stages')->get();
        $profiles = $existing->map(fn ($stage) => $this->profile($stage));

        $added = [];

        foreach ($standard as $stage) {
            if ($profiles->contains($this->profile($stage))) {
                continue;
            }

            if ($existing->contains(fn ($row) => mb_strtolower($row->name) === mb_strtolower($stage['name']))) {
                $this->warn('  stages:      a stage called ' . $stage['name'] . ' exists with other flags; not added');

                continue;
            }

            DB::table('service_order_stages')->insert(array_merge(
                array_fill_keys(self::FLAGS, false),
                $stage,
                ['created_at' => now(), 'updated_at' => now()],
            ));

            $added[] = $stage['name'];
        }

        if ($added === []) {
            return;
        }

        $this->line('  stages:      added ' . implode(', ', $added));

        $order = collect($standard)->mapWithKeys(fn ($stage) => [$this->profile($stage) => $stage['order']]);
        $all = DB::table('service_order_stages')->get();

        if ($all->every(fn ($stage) => $order->has($this->profile($stage)))) {
            foreach ($all as $stage) {
                DB::table('service_order_stages')->where('id', $stage->id)
                    ->update(['order' => $order[$this->profile($stage)]]);
            }

            $kept = $all->reject(fn ($stage) => in_array($stage->name, $added, true))->pluck('name');

            if ($kept->isNotEmpty()) {
                $this->line('               kept ' . $kept->implode(', ') . ', put in the standard order');
            }
        }
    }

    /**
     * An installation from before the stages kept open and closed in a status
     * column. The migration that dropped it gives the closed ones a stage and
     * leaves the open ones without -- and the planner only shows a work order
     * through its stage. They get the stage the application would have put them
     * in: planned when an appointment is linked, as linking one does, and new
     * otherwise.
     */
    private function ordersWithoutStage(array $before): void
    {
        if ($before['tables']->contains('service_order_stages')) {
            return;
        }

        $planned = DB::table('service_order_stages')->where('is_planned_state', true)->orderBy('order')->value('id');
        $new = DB::table('service_order_stages')
            ->where('is_plannable_state', true)
            ->where('is_planned_state', false)
            ->where('is_planning_cancelled_state', false)
            ->orderBy('order')->value('id');

        $orders = DB::table('service_orders')->whereNull('service_order_stage_id')->pluck('id');

        if ($orders->isEmpty() || !$planned || !$new) {
            return;
        }

        $with_appointment = DB::table('eventables')
            ->join('events', 'events.id', '=', 'eventables.event_id')
            ->where('eventables.eventable_type', ServiceOrder::class)
            ->whereIn('eventables.eventable_id', $orders)
            ->whereNull('events.deleted_at')
            ->distinct()
            ->pluck('eventables.eventable_id');

        $without = $orders->diff($with_appointment);

        DB::table('service_orders')->whereIn('id', $with_appointment)->update(['service_order_stage_id' => $planned]);
        DB::table('service_orders')->whereIn('id', $without)->update(['service_order_stage_id' => $new]);

        $names = DB::table('service_order_stages')->whereIn('id', [$planned, $new])->pluck('name', 'id');

        $this->line('  work orders: ' . $orders->count() . ' open ones without a stage: '
            . $with_appointment->count() . ' to ' . $names[$planned] . ' (an appointment is linked), '
            . $without->count() . ' to ' . $names[$new]);
    }

    /**
     * Closed in the old installation, which never wrote down when. The list of
     * closed work orders sorts on it and the dashboard counts by it; the last
     * change to the record is the closest the old data gets.
     */
    private function closingDates(): void
    {
        $closed_stages = DB::table('service_order_stages')
            ->where(fn ($q) => $q->where('is_closed_state', true)->orWhere('is_invoiced_state', true))
            ->pluck('id');

        $orders = DB::table('service_orders')
            ->whereIn('service_order_stage_id', $closed_stages)
            ->whereNull('closed_on')
            ->update(['closed_on' => DB::raw('DATE(COALESCE(updated_at, created_at))')]);

        $tickets = DB::table('tickets')
            ->where('status', 'Gesloten')
            ->whereNull('closed_on')
            ->update(['closed_on' => DB::raw('DATE(COALESCE(updated_at, created_at))')]);

        if ($orders + $tickets > 0) {
            $this->line('  closed on:   ' . $orders . ' work order(s), ' . $tickets
                . ' ticket(s) dated from their last change');
        }
    }

    /**
     * The old installation let anyone be put on an appointment. Here only who is
     * plannable shows up in the planner, so after the import it is empty. Whoever
     * actually carried out work there is plannable here, and takes a field seat,
     * by the same rule the seat migration uses.
     */
    private function plannableUsers(array $before): void
    {
        if ($before['columns']->contains('users.plannable')) {
            return;
        }

        $ours = DB::table('roleables')
            ->join('roles', 'roles.id', '=', 'roleables.role_id')
            ->where('roles.name', Role::SUPERADMIN)
            ->where('roleables.roleable_type', User::class)
            ->pluck('roleables.roleable_id');

        $worked = DB::table('userables')->where('type', 'executing')->distinct()->pluck('user_id');

        if (DB::getSchemaBuilder()->hasColumn('service_jobs', 'completed_by')) {
            $worked = $worked->merge(
                DB::table('service_jobs')->whereNotNull('completed_by')->distinct()->pluck('completed_by')
            );
        }

        $worked = $worked->unique()->diff($ours);

        $users = DB::table('users')->whereIn('id', $worked)->whereNull('deleted_at')->where('plannable', false);
        $emails = $users->pluck('email');

        if ($emails->isEmpty()) {
            return;
        }

        $users->update(['plannable' => true, 'seat_type' => 'field']);

        $this->line('  planner:     plannable, on a field seat: ' . $emails->implode(', '));
    }

    /**
     * Rights that did not exist in the old installation were never granted, so
     * the role that matches one of ours gets what that role is given here for
     * them. Rights that did exist are left as the customer had them: there,
     * not granting was a choice.
     *
     * The administrator too: being one lets you past every check except the
     * explicit ones, and assistant.use is one of those.
     */
    private function newRights(array $before): void
    {
        if ($before['rights']->isEmpty()) {
            return;
        }

        $old = $before['rights']->flip();

        foreach (include base_path('database/seeders/data/tenant_roles.php') as $name => $slug) {
            $role = Role::where('name', $name)->first();

            if (!$role) {
                continue;
            }

            $standard = collect(include base_path("database/seeders/data/{$slug}_permissions.php"));
            $new = $standard->reject(fn ($right) => $old->has($right));
            $withheld = $new->intersect(self::LEFT_TO_THE_CUSTOMER);
            $new = $new->diff(self::LEFT_TO_THE_CUSTOMER);

            $ids = Permission::whereIn('name', $new)->pluck('id', 'name');
            $missing = $ids->except($role->permissions()->pluck('permissions.name')->all());

            if ($missing->isNotEmpty()) {
                $role->permissions()->syncWithoutDetaching($missing->values());

                $this->line('  rights:      ' . $role->name . ' gets ' . $missing->count()
                    . ' that did not exist before: ' . $missing->keys()->sort()->implode(', '));
            }

            if ($withheld->isNotEmpty()) {
                $this->warn('               not granted, the customer decides: ' . $withheld->implode(', '));
            }
        }
    }

    /** The roles a new customer is given, with their rights. The doctor asks for every one. */
    private function missingRoles(): void
    {
        $added = [];

        foreach (include base_path('database/seeders/data/tenant_roles.php') as $name => $slug) {
            if (Role::where('name', $name)->exists()) {
                continue;
            }

            $rights = include base_path("database/seeders/data/{$slug}_permissions.php");

            $role = Role::create(['name' => $name]);
            $role->permissions()->syncWithoutDetaching(Permission::whereIn('name', $rights)->pluck('id'));

            $added[] = $name;
        }

        if ($added !== []) {
            $this->line('  roles:       added ' . implode(', ', $added));
        }
    }

    private function seats(): void
    {
        $seats = DB::table('users')
            ->whereNull('deleted_at')
            ->whereNotIn('id', DB::table('roleables')
                ->join('roles', 'roles.id', '=', 'roleables.role_id')
                ->where('roles.name', Role::SUPERADMIN)
                ->select('roleables.roleable_id'))
            ->selectRaw('seat_type, COUNT(*) AS n')
            ->groupBy('seat_type')
            ->pluck('n', 'seat_type');

        $this->line('  seats:       ' . (int) ($seats['field'] ?? 0) . ' field, '
            . (int) ($seats['office'] ?? 0) . ' office -- check the package covers them');
    }
}
