<?php

namespace App\Domain\Tools\Read;

use App\Domain\Assistant\Reading\RecordKinds;
use App\Domain\Planning\Clock;
use App\Domain\Tools\Read\Concerns\ReportsTheWholeCount;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Activity;
use App\Models\ActivityChange;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\GeneralSetting;
use App\Models\IntegrationConnection;
use App\Models\LedgerAccount;
use App\Models\MaintenanceContract;
use App\Models\MaintenanceContractTemplate;
use App\Models\Material;
use App\Models\PriceFormula;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Role;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\StandardAttachment;
use App\Models\StandardEmail;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserPlanGroup;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Reads the history the signal layer writes.
 *
 * Because every change is stored as a row per field, with the raw value and the
 * readable label both before and after, this answers questions no summary line
 * could: who moved a werkbon to a stage, what a price was before it was
 * corrected, which records one action touched.
 *
 * History has no permission of its own. Everywhere else in this application a
 * timeline is reached through the record it belongs to, so you may read an entry
 * exactly when you may read its subject — and that is enforced here by narrowing
 * the query to visible subjects, not by a gate on the tool. An entry whose
 * subject cannot be resolved is never returned, so entries written before the
 * subject columns existed stay out rather than defaulting to visible.
 */
class SearchActivityTool implements Tool
{
    use ReportsTheWholeCount;

    /**
     * The records whose history can be searched; the model names them by their
     * noun in RecordKinds.
     *
     * These are the things an entry is *attached* to. History is linked through
     * the activityables pivot, exactly as the timeline on a page reads it — the
     * subject_type and subject_id columns on the row itself are filled in for
     * almost nothing and are not what any screen in this application goes by.
     */
    private const SUBJECTS = [
        ServiceOrder::class,
        Ticket::class,
        Asset::class,
        Event::class,
        Customer::class,
        MaintenanceContract::class,
        Project::class,
        PriceFormula::class,
        Quote::class,
        Product::class,
        ProductAttribute::class,
        Material::class,
        LedgerAccount::class,
        IntegrationConnection::class,
        Supplier::class,
        MaintenanceContractTemplate::class,
        ServiceOrderStage::class,
        User::class,
        Role::class,
        StandardEmail::class,
        StandardAttachment::class,
        UserPlanGroup::class,
        UserRole::class,
        EventType::class,
        Company::class,
        GeneralSetting::class,
    ];

    /** @var array<string, class-string>|null */
    private static ?array $subject_types = null;

    /**
     * The same records by the noun the model calls them, from RecordKinds.
     *
     * @return array<string, class-string>
     */
    private static function subjectTypes(): array
    {
        return self::$subject_types ??= array_combine(array_map(RecordKinds::noun(...), self::SUBJECTS), self::SUBJECTS);
    }

    public static function name(): string
    {
        return 'search_activity';
    }

    public function description(): string
    {
        return 'Doorzoekt de geschiedenis van wijzigingen: wie heeft wat wanneer veranderd, '
            . 'met de waarde ervoor en erna. Gebruik dit voor elke vraag over het verleden — '
            . '"wie heeft dit gewijzigd", "wanneer is dit afgesloten", "wat is er vorige week gebeurd" — '
            . 'in plaats van de huidige stand te raden. Staat bij een wijziging via = tray, dan deed '
            . 'de persoon het door iets uit het bakje te plakken.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'subject_type' => [
                    'type' => 'string',
                    'enum' => array_keys(self::subjectTypes()),
                    'description' => 'Het soort record waarover je de geschiedenis wilt.',
                ],
                'subject_id' => [
                    'type' => 'integer',
                    'description' => 'Het id van dat record. Vereist samen met subject_type voor één record.',
                ],
                'field' => [
                    'type' => 'string',
                    'description' => 'Alleen wijzigingen van dit veld, bijvoorbeeld service_order_stage_id.',
                ],
                'from' => [
                    'type' => 'string',
                    'description' => 'Begindatum, als JJJJ-MM-DD.',
                ],
                'to' => [
                    'type' => 'string',
                    'description' => 'Einddatum, als JJJJ-MM-DD.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    /**
     * Everyone may ask; the subject scope decides what comes back. A monteur who
     * can see four werkbonnen sees the history of those four and of nothing else,
     * which is the same answer they would get by opening them one by one.
     */
    public function authorize(User $user, array $arguments): bool
    {
        return true;
    }

    /** Een vraag over het verleden moet worden vertaald naar een record, een veld en een periode, en het antwoord is een reeks wijzigingen waar nog een conclusie uit moet komen. */
    public static function difficulty(): int
    {
        return 5;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }

    public function execute(ToolCall $call): ToolResult
    {
        $limit = (int) config('assistant.max_results', 25);

        $query = Activity::query()->with(['fieldChanges', 'attachments', 'user:id,name']);

        /**
         * Subject visibility says which records this person may look at; it says
         * nothing about the sensitive entries on them. Both gates apply.
         */
        $query->visibleTo($call->user);

        $this->constrainToVisibleSubjects($query, $call->user);

        if ($subject_type = $call->stringArgument('subject_type')) {
            if (!isset(self::subjectTypes()[$subject_type])) {
                return ToolResult::failed(
                    'Onbekend soort record. Kies uit: ' . implode(', ', array_keys(self::subjectTypes())) . '.'
                );
            }

            $this->attachedTo($query, self::subjectTypes()[$subject_type], $call->integerArgument('subject_id'));
        }

        /**
         * An id without a type would match that number across every kind of
         * record at once, quietly mixing werkbon 42 with machine 42. Asking for
         * the type back is cheaper than letting the model reason about a list
         * whose rows are about different things.
         */
        if ($call->integerArgument('subject_id') && blank($subject_type)) {
            return ToolResult::failed('Geef ook subject_type op wanneer je een subject_id gebruikt.');
        }

        if ($field = $call->stringArgument('field')) {
            $query->whereHas('fieldChanges', fn (Builder $q) => $q->where('field', $field));
        }

        /**
         * Compared as moments rather than by date, because "since the first" means
         * since midnight here, not since midnight UTC — two hours of the previous
         * evening otherwise fall on the wrong side of the boundary.
         */
        if ($from = $call->dateArgument('from')) {
            $query->where('occurred_at', '>=', Clock::startOfLocalDay($from));
        }

        if ($to = $call->dateArgument('to')) {
            $query->where('occurred_at', '<', Clock::startOfLocalDay($to->addDay()));
        }

        foreach (['from', 'to'] as $key) {
            if ($call->wasGiven($key) && $call->dateArgument($key) === null) {
                return ToolResult::failed('Geef ' . $key . ' als een echte datum in de vorm JJJJ-MM-DD.');
            }
        }

        $matching = clone $query;
        $activities = $query->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get();

        $rows = $activities->map(fn (Activity $activity) => [
            'activity_id' => $activity->id,
            /**
             * On the clock the timeline shows it on. Reported raw, the assistant
             * said a werkbon was closed at 18:39 when every screen in the
             * application says 20:39 — and somebody reading a history back has no
             * way to tell which of the two is the one they remember.
             */
            'occurred_at' => $activity->occurred_at === null
                ? null
                : Clock::toLocal($activity->occurred_at)->toDateTimeString(),
            'event_key' => $activity->event_key,
            'description' => $activity->description,
            /**
             * What the entry is about when it hangs off another record: "Aangemaakt"
             * on a customer is a machine being added, and only this says which.
             */
            'about' => $activity->metadata['subject_label'] ?? null,
            'subject' => $this->subjectLabels($activity),
            'attached_to' => collect($activity->attachments)
                ->map(fn ($row) => (array_search($row->activityable_type, self::subjectTypes(), true)
                    ?: class_basename($row->activityable_type)) . ' #' . $row->activityable_id)
                ->all(),
            /**
             * Older entries recorded who did it as a link rather than a name, so
             * the name is read back the same way the timeline on a page does.
             * Taking actor_name alone leaves every one of them anonymous.
             */
            'actor' => $activity->actor_name ?? $activity->user?->name,
            'actor_type' => $activity->actor_type,
            'via' => $activity->via,
            'changes' => $activity->fieldChanges->map(fn (ActivityChange $change) => [
                'field' => $change->field,
                'label' => $change->label,
                'from' => $change->old_label ?? $change->old_value,
                'to' => $change->new_label ?? $change->new_value,
            ])->all(),
        ])->all();

        $content = ['activities' => $rows];

        if ($rows === []) {
            $content['note'] = 'Geen wijzigingen gevonden die aan dit record gekoppeld zijn. Dat betekent niet '
                . 'per se dat er niets gebeurd is: regels van voor de invoering van de tijdlijn zijn nergens '
                . 'aan gekoppeld en blijven daarom buiten beeld.';
        }

        return $this->answerWithCount($content, count($rows), $matching, $limit, 'wijzigingen');
    }

    /**
     * Narrows to entries hanging off one kind of record, optionally one in
     * particular, through the same pivot a page's timeline reads.
     */
    private function attachedTo(Builder $query, string $class, ?int $id = null): void
    {
        $query->whereExists(function ($pivot) use ($class, $id) {
            $pivot->select(DB::raw(1))
                ->from('activityables')
                ->whereColumn('activityables.activity_id', 'activities.id')
                ->where('activityables.activityable_type', $class);

            if ($id) {
                $pivot->where('activityables.activityable_id', $id);
            }
        });
    }

    /**
     * Narrows the query to entries this user may see.
     *
     * An entry qualifies when it is attached to at least one record they may
     * open, which is the same answer they would get by opening those records one
     * by one and reading the timeline on each. The rule is the read layer's, not
     * one of this tool's own (RecordKinds::mayRead and the model's visibility
     * scope). Whoever the scope keeps nothing from has every record's history,
     * also of a record deleted for good — "wie heeft klant X verwijderd?". Whoever
     * it narrows for has the history of the records it lets through, soft-deleted
     * ones included; a record no longer in the table is past any scope, so its
     * history is only for whoever has the right to every record of the kind. The
     * two subjects the assistant never reads as rows (a koppeling, an instelling)
     * keep their history behind their policy's viewAny. Kinds that are not a
     * subject here — a material line — grant nothing; they travel attached to the
     * werkbon they happened on, and are reached through that.
     */
    private function constrainToVisibleSubjects(Builder $query, User $user): void
    {
        $query->whereExists(function ($pivot) use ($user) {
            $pivot->select(DB::raw(1))
                ->from('activityables')
                ->whereColumn('activityables.activity_id', 'activities.id')
                ->where(function ($outer) use ($user) {
                    $outer->whereRaw('1 = 0');

                    foreach (self::SUBJECTS as $class) {
                        $this->orVisibleSubject($outer, $class, $user);
                    }
                });
        });
    }

    /** @param  class-string<Model>  $class */
    private function orVisibleSubject($outer, string $class, User $user): void
    {
        if (!RecordKinds::isReadable($class)) {
            if ($user->can('viewAny', $class)) {
                $outer->orWhere('activityables.activityable_type', $class);
            }

            return;
        }

        if (!RecordKinds::mayRead($class, $user)) {
            return;
        }

        if (!RecordKinds::narrowsFor($class, $user)) {
            $outer->orWhere('activityables.activityable_type', $class);

            return;
        }

        $model = new $class;
        $records = in_array(SoftDeletes::class, class_uses_recursive($class), true) ? $class::withTrashed() : $class::query();

        $outer->orWhere(fn ($q) => $q
            ->where('activityables.activityable_type', $class)
            ->whereIn('activityables.activityable_id', RecordKinds::constrain($records, $user)->select($model->getQualifiedKeyName())));
    }

    /**
     * What the entry is about, read from what it is attached to.
     *
     * @return array<int, string>
     */
    private function subjectLabels(Activity $activity): array
    {
        return collect($activity->attachments)
            ->map(fn ($row) => array_search($row->activityable_type, self::subjectTypes(), true)
                ?: class_basename($row->activityable_type))
            ->unique()
            ->values()
            ->all();
    }
}
