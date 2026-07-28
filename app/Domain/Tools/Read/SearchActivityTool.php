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
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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
    private const SUBJECT_TYPES = [
        'werkbon' => ServiceOrder::class,
        'machine' => Asset::class,
        'afspraak' => Event::class,
        'klant' => Customer::class,
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
                'field' => [
                    'type' => 'string',
                    'description' => 'Alleen wijzigingen van dit veld, bijvoorbeeld service_order_stage_id.',
                ],
                'actor_type' => [
                    'type' => 'string',
                    'enum' => ['user', 'ai', 'system'],
                    'description' => 'Wie de wijziging deed: een persoon, de assistent of het systeem.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Alleen wijzigingen door deze gebruiker.',
                ],
                'from' => [
                    'type' => 'string',
                    'description' => 'Begindatum, als JJJJ-MM-DD.',
                ],
                'to' => [
                    'type' => 'string',
                    'description' => 'Einddatum, als JJJJ-MM-DD.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum aantal resultaten.',
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
        $limit = min($call->integerArgument('limit') ?? 15, (int) config('assistant.max_results', 25));

        $query = Activity::query()->with('fieldChanges');

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

            $query->where('subject_type', self::SUBJECT_TYPES[$subject_type]);
        }

        /**
         * An id without a type would match that number across every kind of
         * record at once, quietly mixing werkbon 42 with machine 42. Asking for
         * the type back is cheaper than letting the model reason about a list
         * whose rows are about different things.
         */
        if ($subject_id = $call->integerArgument('subject_id')) {
            if (blank($subject_type)) {
                return ToolResult::failed('Geef ook subject_type op wanneer je een subject_id gebruikt.');
            }

            $query->where('subject_id', $subject_id);
        }

        if ($actor_type = $call->stringArgument('actor_type')) {
            $query->where('actor_type', $actor_type);
        }

        if ($user_id = $call->integerArgument('user_id')) {
            $query->where('user_id', $user_id);
        }

        if ($field = $call->stringArgument('field')) {
            $query->whereHas('fieldChanges', fn (Builder $q) => $q->where('field', $field));
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
            'subject' => $this->subjectLabel($activity),
            'subject_id' => $activity->subject_id,
            'actor' => $activity->actor_name,
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
            $content['note'] = 'Geen wijzigingen gevonden. Let op: dit betekent niet per se dat er niets is gebeurd — '
                . 'oudere regels zijn nog niet aan een record gekoppeld en blijven daarom buiten beeld.';
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
    private function constrainToVisibleSubjects(Builder $query, User $user): void
    {
        $query->where(function (Builder $outer) use ($user) {
            $outer
                ->where(fn (Builder $q) => $q
                    ->where('subject_type', ServiceOrder::class)
                    ->whereIn('subject_id', ServiceOrder::visibleTo($user)->select('service_orders.id')))
                ->orWhere(fn (Builder $q) => $q
                    ->where('subject_type', Asset::class)
                    ->whereIn('subject_id', Asset::visibleTo($user)->select('assets.id')))
                ->orWhere(fn (Builder $q) => $q
                    ->where('subject_type', Event::class)
                    ->whereIn('subject_id', Event::visibleTo($user)->select('events.id')));

            if ($user->can('list', Customer::class)) {
                $outer->orWhere('subject_type', Customer::class);
            }
        });
    }

    private function subjectLabel(Activity $activity): ?string
    {
        $name = array_search($activity->subject_type, self::SUBJECT_TYPES, true);

        return $name === false ? $activity->subject_type : $name;
    }
}
