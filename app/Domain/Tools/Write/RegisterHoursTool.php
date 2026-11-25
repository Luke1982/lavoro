<?php

namespace App\Domain\Tools\Write;

use App\Actions\Appointments\ExecutionTimes;
use App\Actions\Appointments\RegisterExecutionTimesAction;
use App\Domain\Planning\Clock;
use App\Domain\Tools\ChecksBeforeConfirming;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\Concerns\ResolvesBeforeConfirming;
use App\Domain\Tools\Write\Concerns\ValidatesLikeTheForm;
use App\Enums\EventCompletionStatus;
use App\Http\Requests\EventExecutionUpdateRequest;
use App\Models\Event;
use App\Models\EventUserExecution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A technician's own hours on an appointment, told rather than typed: "acht tot
 * half vijf met een half uur pauze".
 *
 * The same thing the clock on the planner does, through the same checks, with
 * one difference it says out loud: the screen takes a signature with the times
 * and a chat cannot give one. The times are kept and the part stays open, so
 * the window on the planner opens on them and only the signature is left. A
 * part shown as finished without the signature the screen insists on would be
 * the one thing this must not do.
 *
 * Never picks between appointments. Two on the same day are two places somebody
 * was, and booking the hours on the wrong one moves them to another werkbon.
 */
class RegisterHoursTool implements ChecksBeforeConfirming, Confirmable, Tool
{
    use ResolvesBeforeConfirming;
    use ValidatesLikeTheForm;

    private const SIGNATURE_MISSING = 'Nog niet afgerond: een handtekening kan niet via de chat. '
        . 'Zet die met de klok op de afspraak in de planner; de tijden staan er dan al in.';

    public static function name(): string
    {
        return 'register_hours';
    }

    public function description(): string
    {
        return 'Registreert de gewerkte uren van een monteur op zijn afspraak (begin, eind, pauze, reistijd), '
            . 'zoals de klok in de planner. Zonder handtekening: het deel blijft open tot die in de planner '
            . 'gezet is. Meer afspraken die dag? Dan komt er een keuze; kies nooit zelf.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'description' => 'JJJJ-MM-DD, standaard vandaag.'],
                'start' => ['type' => 'string', 'description' => 'UU:MM, standaard de geplande.'],
                'end' => ['type' => 'string', 'description' => 'UU:MM, standaard de geplande; vóór start = volgende dag.'],
                'break_minutes' => ['type' => 'integer', 'description' => 'Standaard de geplande pauze.'],
                'travel_minutes' => ['type' => 'integer', 'description' => 'Reistijd.'],
                'event_id' => ['type' => 'integer', 'description' => 'De gekozen afspraak.'],
                'user_id' => ['type' => 'integer', 'description' => 'Alleen voor een ander dan de gebruiker.'],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    /**
     * Asked again of every candidate when no appointment is named yet, since the
     * policy is about one appointment and there is none to put to it here.
     */
    public function authorize(User $user, array $arguments): bool
    {
        $call = new ToolCall(self::name(), $arguments, $user);
        $event_id = $call->integerArgument('event_id');
        $event = $event_id === null ? null : Event::find($event_id);

        return $event === null || $this->mayRegister($user, $event, $this->targetIdOf($call));
    }

    /** Tijden uit een zin halen en de juiste afspraak laten kiezen. */
    public static function difficulty(): int
    {
        return 3;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }

    public function previewOf(ToolCall $call): string
    {
        $resolved = $this->resolution($call);

        if ($resolved instanceof ToolResult) {
            return 'Uren registreren.';
        }

        return 'Uren van ' . $resolved['executor']->name . ' op ' . $resolved['event']->label()
            . ': ' . $this->timesLine($resolved['times']) . '. ' . self::SIGNATURE_MISSING;
    }

    public function execute(ToolCall $call): ToolResult
    {
        $resolved = $this->resolution($call);

        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        ['event' => $event, 'executor' => $executor, 'times' => $times] = $resolved;

        $execution = app(RegisterExecutionTimesAction::class)->execute($event, $executor, $times, complete: false);

        return ToolResult::ok(
            [
                'event_id' => $event->id,
                'user' => $executor->name,
                'times' => $this->timesLine($times),
                'hours_worked' => $execution->hoursWorked((int) $times->actual_breaktime_minutes),
                'status' => $execution->completion_status,
                'missing' => 'handtekening',
                'service_order_id' => $event->serviceOrders->first()?->id,
                'note' => self::SIGNATURE_MISSING . ' Zeg dat de gebruiker; noem het niet afgerond.',
            ],
            'Uren van ' . $executor->name . ' vastgelegd op ' . $event->label()
                . ' (' . $this->timesLine($times) . '); handtekening ontbreekt nog.',
        );
    }

    /**
     * The appointment, whose part it is and the times to store, or why not.
     *
     * A said end before the said start is the next morning: a shift that ran
     * past midnight is told as "tien tot twee", and refusing it would leave the
     * only honest way to say it unsayable. The same end and start stays refused,
     * by the form's own rule.
     *
     * @return ToolResult|array{event: Event, executor: User, times: ExecutionTimes}
     */
    private function resolve(ToolCall $call): ToolResult|array
    {
        $target_id = $this->targetIdOf($call);
        $executor = $target_id === $call->user->id ? $call->user : User::find($target_id);

        if ($executor === null) {
            return ToolResult::notFound('Monteur #' . $target_id);
        }

        $day = $call->dateArgument('date');

        if ($call->wasGiven('date') && $day === null) {
            return ToolResult::failed('Geef date als JJJJ-MM-DD, bijvoorbeeld ' . Clock::today() . '.');
        }

        $events = $this->candidatesFor($call, $executor, $day ?? Clock::todayAsDate());

        if ($events instanceof ToolResult) {
            return $events;
        }

        $event = $events->first();
        $member = $event->executingUsers->firstWhere('id', $executor->id);
        $execution = $event->executions->firstWhere('user_id', $executor->id);
        [$planned_start, $planned_end] = $event->plannedTimesFor($member, Clock::zone());

        /**
         * The day the hours were worked: the one asked about, or for an
         * appointment named without a day the day it starts. An appointment
         * running over several days is worked on each of them, so a time said
         * for the third day must not land on the first.
         */
        $local_date = $call->wasGiven('event_id') && $day === null
            ? Clock::toLocal($event->start)->toDateString()
            : ($day ?? Clock::todayAsDate())->toDateString();

        $starts_at = $this->momentOf($call->stringArgument('start'), $local_date, $execution?->actual_start ?? $planned_start);
        $ends_at = $this->momentOf($call->stringArgument('end'), $local_date, $execution?->actual_end ?? $planned_end);

        if ($starts_at === null || $ends_at === null) {
            return ToolResult::failed('Geef start en end als UU:MM, bijvoorbeeld 08:00 en 16:30.');
        }

        if ($call->wasGiven('end') && $ends_at->lessThan($starts_at)) {
            $ends_at = $ends_at->addDay();
        }

        $times = new ExecutionTimes(
            actual_start: $starts_at->utc()->format('Y-m-d H:i:s'),
            actual_end: $ends_at->utc()->format('Y-m-d H:i:s'),
            travel_time_minutes: $call->integerArgument('travel_minutes') ?? (int) $execution?->travel_time_minutes,
            actual_breaktime_minutes: $call->integerArgument('break_minutes')
                ?? $execution?->actual_breaktime_minutes
                ?? (int) $member?->pivot->breaktime,
        );

        $validator = $this->asTheFormWould(
            EventExecutionUpdateRequest::class,
            $times->toArray(),
            without_rules: ['signature_base64'],
        );

        if ($validator->fails()) {
            return ToolResult::failed($this->complaintOf($validator));
        }

        return ['event' => $event, 'executor' => $executor, 'times' => $times];
    }

    /**
     * The one appointment the hours go on, or what stands in the way: none, one
     * that is already finished or cancelled, or several to choose between.
     *
     * @return Collection<int, Event>|ToolResult
     */
    private function candidatesFor(ToolCall $call, User $executor, CarbonImmutable $day): Collection|ToolResult
    {
        $event_id = $call->integerArgument('event_id');

        $events = Event::query()
            ->visibleTo($call->user)
            ->notCancelled()
            ->whereHas('executingUsers', fn ($q) => $q->where('users.id', $executor->id))
            ->when($event_id !== null, fn ($q) => $q->whereKey($event_id))
            ->when($event_id === null || $call->wasGiven('date'), fn ($q) => $q
                ->where('start', '<', Clock::startOfLocalDay($day->addDay()))
                ->where('end', '>', Clock::startOfLocalDay($day)))
            ->with([
                'eventType:id,name',
                'executingUsers' => fn ($q) => $q->where('users.id', $executor->id),
                'executions' => fn ($q) => $q->where('user_id', $executor->id),
                'serviceOrders:id,customer_id',
                'serviceOrders.customer:id,name',
                'customers:id,name',
            ])
            ->orderBy('start')
            ->get();

        /** Unnamed until a policy has said yes: a number asked about is not a name to hand out. */
        $who = $executor->is($call->user) ? $executor->name : 'monteur #' . $executor->id;

        if ($events->isEmpty()) {
            return ToolResult::failed($event_id !== null
                ? 'Op afspraak #' . $event_id . ' staat ' . $who . ($call->wasGiven('date') ? ' die dag' : '')
                    . ' niet, hij is geannuleerd, of je mag hem niet zien.'
                : Str::ucfirst($who) . ' heeft op ' . $day->format('d-m-Y') . ' geen afspraak.');
        }

        $permitted = $events->filter(fn (Event $event) => $this->mayRegister($call->user, $event, $executor->id));

        if ($permitted->isEmpty()) {
            return ToolResult::denied();
        }

        $open = $permitted->reject(fn (Event $event) => $this->isClosed($event->executions->first()));

        if ($open->isEmpty()) {
            $status = $permitted->first()->executions->first()?->completion_status;

            return ToolResult::failed(
                'Het deel van ' . $executor->name . ' op ' . $permitted->first()->label() . ' is al '
                    . mb_strtolower((string) $status) . '; tijden invullen kan pas weer als ze zijn vrijgegeven.'
            );
        }

        if ($open->count() > 1) {
            $question = 'Op welke afspraak horen de uren?';

            return ToolResult::failedWith(
                [
                    'error' => $executor->name . ' heeft ' . $open->count() . ' open afspraken op die dag.',
                    'choice' => [
                        'question' => $question,
                        'options' => $open->map(fn (Event $event) => [
                            'label' => mb_substr($event->label(), 0, 120),
                            'reference' => 'afspraak #' . $event->id,
                            'link' => null,
                        ])->values()->all(),
                    ],
                    'note' => 'Er is niets opgeslagen. De gebruiker krijgt knoppen; roep daarna opnieuw aan met '
                        . 'event_id. Verdeel de uren niet zelf.',
                ],
                $question,
            );
        }

        return $open->values();
    }

    private function mayRegister(User $user, Event $event, int $target_id): bool
    {
        return $target_id === $user->id
            ? $user->can('executeOwn', $event)
            : $user->can('executeOthers', [$event, $target_id]);
    }

    /** Finished or cancelled: the planner shows no clock on either until somebody releases it. */
    private function isClosed(?EventUserExecution $execution): bool
    {
        return in_array($execution?->completion_status, [
            EventCompletionStatus::completed->value,
            EventCompletionStatus::cancelled->value,
        ], true);
    }

    /** Whose hours: the person asking, unless somebody else is named. */
    private function targetIdOf(ToolCall $call): int
    {
        return $call->integerArgument('user_id') ?? $call->user->id;
    }

    /**
     * A wall-clock time said for this appointment's day, or the fallback the
     * window on the planner opens with. "8", "8:00", "16.30" all count.
     */
    private function momentOf(?string $said, string $local_date, mixed $fallback): ?CarbonImmutable
    {
        if ($said === null || $said === '') {
            return CarbonImmutable::parse($fallback)->setTimezone(Clock::zone());
        }

        if (!preg_match('/^(\d{1,2})(?:[:.](\d{2}))?$/', $said, $parts)) {
            return null;
        }

        $hour = (int) $parts[1];
        $minute = (int) ($parts[2] ?? 0);

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return Clock::fromLocal($local_date . ' ' . sprintf('%02d:%02d', $hour, $minute))?->setTimezone(Clock::zone());
    }

    private function timesLine(ExecutionTimes $times): string
    {
        $from = Clock::toLocal(CarbonImmutable::parse($times->actual_start, 'UTC'));
        $until = Clock::toLocal(CarbonImmutable::parse($times->actual_end, 'UTC'));

        return $from->format('H:i') . '–' . $until->format('H:i')
            . ($from->isSameDay($until) ? '' : ' (volgende dag)')
            . ', pauze ' . $times->actual_breaktime_minutes . ' min'
            . ', reistijd ' . $times->travel_time_minutes . ' min';
    }
}
