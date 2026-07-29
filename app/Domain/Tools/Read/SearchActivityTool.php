<?php

namespace App\Domain\Tools\Read;

use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Activity;
use App\Models\ActivityChange;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Event;
use App\Models\MaintenanceContract;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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
    /**
     * The names a person uses for the things history is kept about, mapped to the
     * classes stored in subject_type.
     *
     * Only subjects with a settled visibility rule appear here. Tickets and
     * projects are deliberately absent: neither has a scope that answers "which
     * of these may this user see", and guessing one would leak the very records
     * the guess was meant to protect.
     */
    /**
     * The vocabulary the model uses, and the records it maps onto.
     *
     * These are the things an entry is *attached* to. History is linked through
     * the activityables pivot, exactly as the timeline on a page reads it — the
     * subject_type and subject_id columns on the row itself are filled in for
     * almost nothing and are not what any screen in this application goes by.
     */
    private const SUBJECT_TYPES = [
        'werkbon' => ServiceOrder::class,
        'storing' => Ticket::class,
        'machine' => Asset::class,
        'afspraak' => Event::class,
        'klant' => Customer::class,
        'onderhoudscontract' => MaintenanceContract::class,
    ];

    public static function name(): string
    {
        return 'search_activity';
    }

    public function description(): string
    {
        return 'Doorzoekt de geschiedenis van wijzigingen: wie heeft wat wanneer veranderd, '
            . 'met de waarde ervoor en erna. Gebruik dit voor elke vraag over het verleden — '
            . '"wie heeft dit gewijzigd", "wanneer is dit afgesloten", "wat is er vorige week gebeurd" — '
            . 'in plaats van de huidige stand te raden.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'subject_type' => [
                    'type' => 'string',
                    'enum' => array_keys(self::SUBJECT_TYPES),
                    'description' => 'Het soort record waarover je de geschiedenis wilt.',
                ],
                'subject_id' => [
                    'type' => 'integer',
                    'description' => 'Het id van dat record. Vereist samen met subject_type voor één record.',
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
            if (!isset(self::SUBJECT_TYPES[$subject_type])) {
                return ToolResult::failed(
                    'Onbekend soort record. Kies uit: ' . implode(', ', array_keys(self::SUBJECT_TYPES)) . '.'
                );
            }

            $this->attachedTo($query, self::SUBJECT_TYPES[$subject_type], $call->integerArgument('subject_id'));
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

        if ($from = $call->stringArgument('from')) {
            $query->whereDate('occurred_at', '>=', $from);
        }

        if ($to = $call->stringArgument('to')) {
            $query->whereDate('occurred_at', '<=', $to);
        }

        $activities = $query->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get();

        $rows = $activities->map(fn (Activity $activity) => [
            'id' => $activity->id,
            'occurred_at' => $activity->occurred_at?->toDateTimeString(),
            'event_key' => $activity->event_key,
            'description' => $activity->description,
            'subject' => $this->subjectLabels($activity),
            'attached_to' => collect($activity->attachments)
                ->map(fn ($row) => (array_search($row->activityable_type, self::SUBJECT_TYPES, true)
                    ?: class_basename($row->activityable_type)) . ' #' . $row->activityable_id)
                ->all(),
            /**
             * Older entries recorded who did it as a link rather than a name, so
             * the name is read back the same way the timeline on a page does.
             * Taking actor_name alone leaves every one of them anonymous.
             */
            'actor' => $activity->actor_name ?? $activity->user?->name,
            'actor_type' => $activity->actor_type,
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

        return ToolResult::ok($content, count($rows) . ' wijziging(en) gevonden.');
    }

    /**
     * Narrows the query to entries whose subject this user may see.
     *
     * Each supported subject contributes its own branch, built from the same
     * scope the rest of the application reads that subject with, so history can
     * never become a way around a restriction that holds everywhere else.
     */
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
     * by one and reading the timeline on each. Types with no visibility rule of
     * their own — a material line, a stage — grant nothing here; they travel
     * attached to the werkbon they happened on, and are reached through that.
     */
    private function constrainToVisibleSubjects(Builder $query, User $user): void
    {
        $query->whereExists(function ($pivot) use ($user) {
            $pivot->select(DB::raw(1))
                ->from('activityables')
                ->whereColumn('activityables.activity_id', 'activities.id')
                ->where(function ($outer) use ($user) {
                    $outer
                        ->where(fn ($q) => $q
                            ->where('activityables.activityable_type', ServiceOrder::class)
                            ->whereIn('activityables.activityable_id', ServiceOrder::visibleTo($user)->select('service_orders.id')))
                        ->orWhere(fn ($q) => $q
                            ->where('activityables.activityable_type', Asset::class)
                            ->whereIn('activityables.activityable_id', Asset::visibleTo($user)->select('assets.id')))
                        ->orWhere(fn ($q) => $q
                            ->where('activityables.activityable_type', Event::class)
                            ->whereIn('activityables.activityable_id', Event::visibleTo($user)->select('events.id')))
                        ->orWhere(fn ($q) => $q
                            ->where('activityables.activityable_type', Ticket::class)
                            ->whereIn('activityables.activityable_id', Ticket::visibleTo($user)->select('tickets.id')));

                    if ($user->can('list', Customer::class)) {
                        $outer->orWhere('activityables.activityable_type', Customer::class);
                    }
                });
        });
    }

    /**
     * What the entry is about, read from what it is attached to.
     *
     * @return array<int, string>
     */
    private function subjectLabels(Activity $activity): array
    {
        return collect($activity->attachments)
            ->map(fn ($row) => array_search($row->activityable_type, self::SUBJECT_TYPES, true)
                ?: class_basename($row->activityable_type))
            ->unique()
            ->values()
            ->all();
    }
}
