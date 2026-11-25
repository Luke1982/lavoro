<?php

namespace App\Domain\Tools\Write;

use App\Actions\Appointments\AppointmentChanges;
use App\Actions\Appointments\CancelAppointmentAction;
use App\Actions\Appointments\CopyAppointmentAction;
use App\Actions\Appointments\MoveAppointmentAction;
use App\Actions\Appointments\UpdateAppointmentAction;
use App\Domain\Planning\Clock;
use App\Domain\Planning\TechnicianAvailability;
use App\Domain\Tools\ChecksBeforeConfirming;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\Concerns\ResolvesBeforeConfirming;
use App\Enums\EventTrigger;
use App\Enums\StandardEmailTriggerType;
use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\User;
use App\Services\StandardEmailTriggerResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Changes an appointment that is already in the diary: moves or stretches it,
 * copies it to other days, marks it voorlopig or definitief, or removes it.
 *
 * Each of those is what the menu on an appointment in the planner does, and
 * each goes through the action the planner uses, so the werkbon going back to
 * "planning vervallen", the standard e-mails, the Google copy and the timeline
 * follow from it here exactly as they do there.
 *
 * One tool with an action rather than five: every definition is sent with every
 * question, and the five share the one thing that matters, which appointment.
 */
class ChangeEventTool implements ChecksBeforeConfirming, Confirmable, Tool
{
    use ResolvesBeforeConfirming;

    private const ACTIONS = ['verplaatsen', 'kopieren', 'voorlopig', 'definitief', 'verwijderen'];

    public static function name(): string
    {
        return 'change_event';
    }

    public function description(): string
    {
        return 'Wijzigt een bestaande afspraak (event_id uit find_appointments): verplaatsen of '
            . 'korter/langer maken, kopiëren naar andere dagen, voorlopig of definitief maken, of verwijderen. '
            . 'Roep meteen aan; de gebruiker krijgt een knop om te bevestigen.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'description' => 'De afspraak.'],
                'action' => ['type' => 'string', 'enum' => self::ACTIONS, 'description' => 'Wat er gebeurt.'],
                'starts_at' => [
                    'type' => 'string',
                    'description' => 'verplaatsen: nieuw begin, JJJJ-MM-DD UU:MM; zonder ends_at blijft de duur.',
                ],
                'ends_at' => [
                    'type' => 'string',
                    'description' => 'verplaatsen: nieuw eind; alleen dit verlengt of verkort.',
                ],
                'dates' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'kopieren: dagen als JJJJ-MM-DD.',
                ],
            ],
            'required' => ['event_id', 'action'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The policy for what is asked of this appointment. An appointment that
     * cannot be found is not refused here: the tool says it was not found, which
     * tells the model to look it up rather than that it may not.
     */
    public function authorize(User $user, array $arguments): bool
    {
        $event = Event::query()->visibleTo($user)->find($arguments['event_id'] ?? null);

        if ($event === null) {
            return $user->can('viewAny', Event::class);
        }

        return $this->permits($user, $this->actionOf($arguments['action'] ?? null), $event);
    }

    /** De juiste afspraak uit het gesprek halen en zeggen wat ermee moet. */
    public static function difficulty(): int
    {
        return 5;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    public function previewOf(ToolCall $call): string
    {
        $resolved = $this->resolution($call);

        if ($resolved instanceof ToolResult) {
            return 'Afspraak wijzigen: ' . $resolved->summary;
        }

        $event = $resolved['event'];

        return Str::ucfirst($event->label()) . ' ' . match ($resolved['action']) {
            'verplaatsen' => $this->moveWord($resolved['stretch'], false) . ' naar '
                . Event::window($resolved['starts_at'], $resolved['ends_at'])
                . $this->crewNote($event)
                . $this->divergingNote($resolved['shifting'])
                . $this->clashNote(
                    $call->user,
                    $event,
                    [[$resolved['starts_at'], $resolved['ends_at']]],
                    $resolved['diverging_times'],
                )
                . $this->mailNote($event, EventTrigger::event_updated, 1),
            'kopieren' => 'kopiëren naar '
                . implode(', ', array_map(fn (CarbonImmutable $date) => $date->format('d-m-Y'), $resolved['dates']))
                . ' (' . count($resolved['dates']) . ' nieuwe ' . (count($resolved['dates']) === 1 ? 'afspraak' : 'afspraken')
                . ', zelfde tijd, monteurs en werkbon)'
                . $this->clashNote($call->user, $event, array_map(
                    fn (int $days) => CopyAppointmentAction::windowFor($event, $days),
                    $resolved['offsets'],
                ), MoveAppointmentAction::storedDivergingTimes($event->executingUsers()->get()))
                . $this->mailNote($event, EventTrigger::event_created, count($resolved['dates'])),
            'voorlopig' => 'markeren als voorlopig'
                . $this->mailNote($event, EventTrigger::event_updated, 1),
            'definitief' => 'markeren als definitief'
                . $this->mailNote($event, EventTrigger::event_updated, 1),
            'verwijderen' => 'verwijderen'
                . $this->releaseNote($event)
                . $this->registeredNote($event)
                . $this->mailNote($event, EventTrigger::event_deleted, 1),
        } . '.';
    }

    public function execute(ToolCall $call): ToolResult
    {
        $resolved = $this->resolution($call);

        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        $event = $resolved['event'];

        return match ($resolved['action']) {
            'verplaatsen' => $this->move($event, $resolved['starts_at'], $resolved['ends_at'], $resolved['stretch']),
            'kopieren' => $this->copy($event, $resolved['offsets']),
            'voorlopig' => $this->mark($event, true),
            'definitief' => $this->mark($event, false),
            'verwijderen' => $this->remove($event),
        };
    }

    private function move(Event $event, CarbonImmutable $starts_at, CarbonImmutable $ends_at, bool $stretch): ToolResult
    {
        $was = Event::window($event->start, $event->end);

        app(MoveAppointmentAction::class)->execute($event, $starts_at, $ends_at);

        $what = 'Afspraak #' . $event->id . ' ' . $this->moveWord($stretch, true);
        $now = Event::window($starts_at, $ends_at);

        return ToolResult::ok(
            $this->answer($event, ['was' => $was, 'what' => $what . ' naar ' . $now]),
            $what . ' van ' . $was . ' naar ' . $now . '.',
        );
    }

    /**
     * @param  array<int, int>  $offsets
     */
    private function copy(Event $event, array $offsets): ToolResult
    {
        $copies = app(CopyAppointmentAction::class)->execute($event, $offsets);
        $what = 'Afspraak #' . $event->id . ' gekopieerd naar '
            . implode(', ', array_map(fn (Event $copy) => Clock::toLocal($copy->start)->format('d-m-Y'), $copies));

        return ToolResult::ok(
            $this->answer($event, [
                'copied_event_ids' => array_map(fn (Event $copy) => $copy->id, $copies),
                'what' => $what,
            ]),
            $what . '.',
        );
    }

    private function mark(Event $event, bool $preliminary): ToolResult
    {
        app(UpdateAppointmentAction::class)->execute(
            $event,
            new AppointmentChanges(attributes: ['is_preliminary' => $preliminary]),
        );

        $state = $preliminary ? 'voorlopig' : 'definitief';

        return ToolResult::ok(
            $this->answer($event, ['what' => 'Afspraak #' . $event->id . ' staat nu op ' . $state]),
            'Afspraak #' . $event->id . ' gemarkeerd als ' . $state . '.',
        );
    }

    private function remove(Event $event): ToolResult
    {
        $answer = $this->answer($event, ['what' => 'Afspraak #' . $event->id . ' verwijderd']);

        app(CancelAppointmentAction::class)->execute($event);

        return ToolResult::ok($answer, 'Afspraak #' . $event->id . ' (' . $answer['when'] . ') verwijderd.');
    }

    /**
     * What the model is told afterwards: which appointment, when it is now, and
     * where to look, so the next question need not find it again.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function answer(Event $event, array $extra): array
    {
        $event->refresh();
        $order_id = $event->serviceOrders()->value('service_orders.id');

        return [
            'event_id' => $event->id,
            'when' => Event::window($event->start, $event->end),
            'is_preliminary' => (bool) $event->is_preliminary,
            'mechanics' => $event->executingUsers()->orderBy('name')->pluck('name')->all(),
            'service_order_id' => $order_id,
            ...$extra,
            'link' => $order_id ? '/serviceorders/' . $order_id : '/planner',
        ];
    }

    /**
     * The appointment and what is asked of it, checked, or why it cannot be.
     * Run before the button and again on the click: the diary can change in
     * between, and the policy is asked about the appointment that will really be
     * changed, whatever the arguments the call arrived with said.
     *
     * @return ToolResult|array{event: Event, action: string, starts_at?: CarbonImmutable, ends_at?: CarbonImmutable,
     *     stretch?: bool, shifting?: array<int, array{name: string, from: string, to: string}>,
     *     diverging_times?: array<int, array{diverging_start: string, diverging_end: string}>,
     *     dates?: array<int, CarbonImmutable>, offsets?: array<int, int>}
     */
    private function resolve(ToolCall $call): ToolResult|array
    {
        $action = $this->actionOf($call->stringArgument('action'));

        if ($action === null) {
            return ToolResult::failed('Kies als action een van: ' . implode(', ', self::ACTIONS) . '.');
        }

        $event_id = $call->integerArgument('event_id');
        $event = $event_id === null ? null : Event::query()->visibleTo($call->user)->find($event_id);

        if ($event === null) {
            return ToolResult::notFound('Afspraak #' . $event_id . ' (zoek hem op met find_appointments)');
        }

        if (!$this->permits($call->user, $action, $event)) {
            return ToolResult::denied();
        }

        return match ($action) {
            'verplaatsen' => $this->resolveMove($call, $event),
            'kopieren' => $this->resolveCopy($call, $event),
            'voorlopig', 'definitief' => (bool) $event->is_preliminary === ($action === 'voorlopig')
                ? ToolResult::failed('Afspraak #' . $event->id . ' staat al op ' . $action . '.')
                : ['event' => $event, 'action' => $action],
            'verwijderen' => ['event' => $event, 'action' => $action],
        };
    }

    /**
     * @return ToolResult|array<string, mixed>
     */
    private function resolveMove(ToolCall $call, Event $event): ToolResult|array
    {
        $example = 'bijvoorbeeld ' . Clock::today() . ' 08:00';

        if (!$call->wasGiven('starts_at') && !$call->wasGiven('ends_at')) {
            return ToolResult::failed('Geef bij verplaatsen starts_at, ends_at of allebei, ' . $example . '.');
        }

        $old_start = CarbonImmutable::parse($event->start);
        $old_end = CarbonImmutable::parse($event->end);

        $starts_at = $call->wasGiven('starts_at') ? $call->momentArgument('starts_at') : $old_start;
        $ends_at = $call->wasGiven('ends_at')
            ? $call->momentArgument('ends_at')
            : $starts_at?->addSeconds($old_end->getTimestamp() - $old_start->getTimestamp());

        if ($starts_at === null || $ends_at === null) {
            return ToolResult::failed('Geef tijden als een echte datum en tijd, JJJJ-MM-DD UU:MM, ' . $example . '.');
        }

        if ($ends_at->lessThanOrEqualTo($starts_at)) {
            return ToolResult::failed('De afspraak eindigt dan niet na het begin.');
        }

        if ($starts_at->equalTo($old_start) && $ends_at->equalTo($old_end)) {
            return ToolResult::failed('Afspraak #' . $event->id . ' staat al op ' . Event::window($starts_at, $ends_at) . '.');
        }

        if (!$starts_at->equalTo($old_start) && $starts_at->lessThan(Clock::startOfLocalDay(now()))) {
            return ToolResult::failed('Een afspraak naar het verleden verplaatsen kan niet.');
        }

        return [
            'event' => $event,
            'action' => 'verplaatsen',
            'starts_at' => $starts_at,
            'ends_at' => $ends_at,
            'stretch' => $starts_at->equalTo($old_start),
            'shifting' => app(MoveAppointmentAction::class)->describeDivergingShift($event, $starts_at, $ends_at),
            'diverging_times' => app(MoveAppointmentAction::class)->divergingTimesAfter($event, $starts_at, $ends_at),
        ];
    }

    /**
     * The days the planner would accept from "Naar datum…" (CopyAppointmentAction
     * says which those are), and here also not in the past, as a new appointment
     * from the assistant never is.
     *
     * @return ToolResult|array<string, mixed>
     */
    private function resolveCopy(ToolCall $call, Event $event): ToolResult|array
    {
        $given = $call->argument('dates');

        if (!is_array($given) || $given === []) {
            return ToolResult::failed('Geef bij kopieren de dagen in dates, als lijst van JJJJ-MM-DD.');
        }

        $today = Clock::todayAsDate();
        $dates = [];

        foreach ($given as $value) {
            $date = is_string($value) ? ToolCall::parseDate($value) : null;

            if ($date === null) {
                $shown = is_scalar($value) ? '"' . $value . '"' : 'Een van de dagen';

                return ToolResult::failed($shown . ' is geen datum; geef dagen als JJJJ-MM-DD.');
            }

            $refusal = CopyAppointmentAction::refusalFor($event, $date);

            if ($refusal !== null) {
                return ToolResult::failed($date->format('d-m-Y') . ': ' . $refusal);
            }

            if ($date->toDateString() < $today->toDateString()) {
                return ToolResult::failed('Kopiëren naar een dag in het verleden (' . $date->format('d-m-Y') . ') kan niet.');
            }

            $dates[$date->toDateString()] = [$date, CopyAppointmentAction::offsetTo($event, $date)];
        }

        if (count($dates) > CopyAppointmentAction::MOST_COPIES) {
            return ToolResult::failed('Hooguit ' . CopyAppointmentAction::MOST_COPIES . ' kopieën in één keer.');
        }

        ksort($dates);

        return [
            'event' => $event,
            'action' => 'kopieren',
            'dates' => array_values(array_map(fn (array $pair) => $pair[0], $dates)),
            'offsets' => array_values(array_map(fn (array $pair) => $pair[1], $dates)),
        ];
    }

    /**
     * The planner's own questions: moving and marking are changing the
     * appointment, copying is making new ones, removing is removing it.
     */
    private function permits(User $user, ?string $action, Event $event): bool
    {
        return match ($action) {
            'kopieren' => $user->can('copy', $event),
            'verwijderen' => $user->can('delete', $event),
            default => $user->can('update', $event),
        };
    }

    /** "kopiëren" and "Kopieren" are what the model means by kopieren. */
    private function actionOf(mixed $value): ?string
    {
        $action = Str::lower(Str::ascii(trim((string) $value)));

        return in_array($action, self::ACTIONS, true) ? $action : null;
    }

    /** A new start is a move; the same start with another end is only an adjustment. */
    private function moveWord(bool $stretch, bool $done): string
    {
        return match (true) {
            $stretch && $done => 'aangepast',
            $stretch => 'aanpassen',
            $done => 'verplaatst',
            default => 'verplaatsen',
        };
    }

    private function crewNote(Event $event): string
    {
        $names = $event->executingUsers()->orderBy('name')->pluck('name');

        return $names->count() > 1 ? ', voor alle monteurs (' . $names->implode(', ') . ')' : '';
    }

    /**
     * @param  array<int, array{name: string, from: string, to: string}>  $shifting
     */
    private function divergingNote(array $shifting): string
    {
        return $shifting === [] ? '' : '; afwijkende tijden schuiven mee: ' . collect($shifting)
            ->map(fn (array $times) => $times['name'] . ' ' . $times['from'] . ' → ' . $times['to'])
            ->implode(', ');
    }

    /**
     * The planner shows a double booking as red stripes; a button shows nothing
     * unless it is written on it. Asked of the same check that refuses a new
     * appointment, so time off and diverging times count here too. Another
     * appointment is named only to whoever may see it; to anybody else it is
     * "een andere afspraak", since the button is no way round the diary's own
     * limits. Whoever has diverging times is asked about those, as the
     * planner's grid shows them.
     *
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $windows
     * @param  array<int, array{diverging_start: string, diverging_end: string}>  $diverging_times
     */
    private function clashNote(User $user, Event $event, array $windows, array $diverging_times): string
    {
        $crew = $event->executingUsers()->orderBy('name')->get();

        if ($crew->isEmpty()) {
            return '';
        }

        $availability = app(TechnicianAvailability::class);
        $lines = [];

        foreach ($windows as [$start, $end]) {
            foreach ($availability->clashes($crew, $start, $end, $event->id, $diverging_times) as $clash) {
                $visible = Event::query()->visibleTo($user)
                    ->whereKey(array_map(fn (Event $other) => $other->id, $clash['appointments']))
                    ->pluck('id')
                    ->all();

                $reasons = [
                    ...array_map(fn (Event $other) => in_array($other->id, $visible, true)
                        ? 'afspraak #' . $other->id . ' ' . Event::window($other->start, $other->end)
                        : 'een andere afspraak', $clash['appointments']),
                    ...$clash['time_off'],
                ];

                $lines[] = $clash['user']->name . ' op ' . Clock::toLocal($start)->format('d-m-Y') . ' ('
                    . implode(', ', array_unique($reasons ?: ['buiten de werktijd'])) . ')';
            }
        }

        return $lines === [] ? '' : '; LET OP, niet vrij: ' . implode('; ', array_unique($lines));
    }

    /**
     * What the customer will get without anybody being asked, and what the
     * planner would have offered to send but this will not.
     */
    private function mailNote(Event $event, EventTrigger $trigger, int $times): string
    {
        $matches = StandardEmailTriggerResolver::matching($event, $trigger, [
            StandardEmailTriggerType::background->name,
            StandardEmailTriggerType::confirm->name,
            StandardEmailTriggerType::allowedit->name,
        ]);

        $sent = $matches->where('trigger_type', StandardEmailTriggerType::background->name)
            ->map(fn ($match) => '"' . $match->standardEmail->name . '"')->unique()->values();
        $offered = $matches->where('trigger_type', '!=', StandardEmailTriggerType::background->name)
            ->map(fn ($match) => '"' . $match->standardEmail->name . '"')->unique()->values();

        return ($sent->isEmpty() ? '' : '; de klant krijgt automatisch ' . $sent->implode(', ')
                . ($times > 1 ? ', per kopie (' . $times . '×)' : ''))
            . ($offered->isEmpty() ? '' : '; niet verstuurd: ' . $offered->implode(', ') . ' (dat vraagt de planner per keer)');
    }

    private function releaseNote(Event $event): string
    {
        $orders = $event->serviceOrders()->get();
        $stage = $orders->isEmpty() ? null : ServiceOrderStage::planningCancelled();

        return $stage === null
            ? ''
            : '; werkbon ' . $orders->map(fn (ServiceOrder $order) => $order->number)->implode(', ')
                . ' gaat terug naar "' . $stage->name . '"';
    }

    private function registeredNote(Event $event): string
    {
        $registered = $event->executions()->whereNotNull('actual_start')->count();

        return $registered === 0 ? '' : '; LET OP: er zijn al uren op geregistreerd (' . $registered . ' monteur'
            . ($registered === 1 ? '' : 's') . ')';
    }
}
