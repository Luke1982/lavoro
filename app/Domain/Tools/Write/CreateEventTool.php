<?php

namespace App\Domain\Tools\Write;

use App\Actions\Appointments\AppointmentAssignment;
use App\Actions\Appointments\CreateAppointmentAction;
use App\Actions\Appointments\NewAppointment;
use App\Domain\Planning\TechnicianAvailability;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Event;
use App\Models\EventType;
use App\Models\ServiceOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Puts an appointment in the diary.
 *
 * The first tool here that changes anything, so it goes through
 * CreateAppointmentAction rather than touching Event: the planner screen and the
 * web form already use it, and everything hanging off it — the signals, the
 * timeline entry, the notifications to whoever got assigned — happens because of
 * that and not because this remembered to.
 *
 * It asks before it writes. Not because the model is untrusted with the words,
 * but because it is being trusted with somebody's Tuesday: a misread date is
 * indistinguishable from a correct one until a van turns up.
 */
class CreateEventTool implements Tool
{
    public static function name(): string
    {
        return 'create_event';
    }

    public function description(): string
    {
        return 'Plant een afspraak in de agenda voor een of meer monteurs. '
            . 'Gebruik dit pas als datum, tijd, duur en monteurs allemaal bekend zijn — '
            . 'vraag er anders eerst naar. Er wordt niets gewijzigd voordat de gebruiker bevestigt.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'starts_at' => [
                    'type' => 'string',
                    'description' => 'Begin van de afspraak als JJJJ-MM-DD UU:MM.',
                ],
                'ends_at' => [
                    'type' => 'string',
                    'description' => 'Einde van de afspraak als JJJJ-MM-DD UU:MM.',
                ],
                'user_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'De monteurs die de afspraak uitvoeren.',
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'Korte omschrijving van de afspraak.',
                ],
                'service_order_id' => [
                    'type' => 'integer',
                    'description' => 'De werkbon waar de afspraak bij hoort. Laat weg voor een losse afspraak.',
                ],
            ],
            'required' => ['starts_at', 'ends_at', 'user_ids'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The same question the planner screen asks before it lets anybody drag
     * something into the diary.
     */
    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('create', Event::class);
    }

    /** Uit een gesprek een datum, tijd, duur en de juiste monteurs halen, en dat vastleggen. */
    public static function difficulty(): int
    {
        return 6;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    public function execute(ToolCall $call): ToolResult
    {
        $starts_at = $this->moment($call->stringArgument('starts_at'));
        $ends_at = $this->moment($call->stringArgument('ends_at'));

        if ($starts_at === null || $ends_at === null) {
            return ToolResult::failed('Geef begin en eind als JJJJ-MM-DD UU:MM, bijvoorbeeld ' . now()->format('Y-m-d H:i') . '.');
        }

        if ($ends_at->lessThanOrEqualTo($starts_at)) {
            return ToolResult::failed('De afspraak eindigt niet na het begin.');
        }

        if ($starts_at->lessThan(now()->startOfDay())) {
            return ToolResult::failed('Een afspraak in het verleden inplannen kan niet.');
        }

        $user_ids = $call->integerListArgument('user_ids');

        if ($user_ids === []) {
            return ToolResult::failed('Geef minstens één monteur op.');
        }

        $mechanics = User::query()->whereIn('id', $user_ids)->where('plannable', true)->get();

        if ($mechanics->count() !== count($user_ids)) {
            $missing = array_diff($user_ids, $mechanics->pluck('id')->all());

            return ToolResult::failed('Niet inplanbaar of onbekend: ' . implode(', ', $missing) . '.');
        }

        $service_order_id = $call->integerArgument('service_order_id');

        if ($service_order_id !== null) {
            $visible = ServiceOrder::visibleTo($call->user)->whereKey($service_order_id)->exists();

            if (!$visible) {
                return ToolResult::notFound('Werkbon #' . $service_order_id);
            }
        }

        $event_type_id = EventType::query()->orderBy('id')->value('id');

        if ($event_type_id === null) {
            return ToolResult::failed('Er is geen afspraaksoort ingesteld.');
        }

        $clashes = $this->clashes($mechanics, $starts_at, $ends_at);

        if ($clashes !== []) {
            return ToolResult::failed(
                'Dit botst met wat er al staat: ' . implode('; ', $clashes)
                . '. Kies een ander moment of andere monteurs.'
            );
        }

        $event = app(CreateAppointmentAction::class)->execute(new NewAppointment(
            attributes: [
                'event_type_id' => $event_type_id,
                'start' => $starts_at->format('Y-m-d H:i:s'),
                'end' => $ends_at->format('Y-m-d H:i:s'),
                /** The column is called name; "subject" is only what it is to a reader. */
                'name' => $call->stringArgument('subject'),
                'status' => 'Gepland',
            ],
            eventable_type: $service_order_id ? ServiceOrder::class : null,
            eventable_id: $service_order_id,
            no_service_order: $service_order_id === null,
            assignment: new AppointmentAssignment(user_ids: $mechanics->pluck('id')->all()),
        ));

        return ToolResult::ok(
            [
                'event_id' => $event->id,
                'starts_at' => $starts_at->format('Y-m-d H:i'),
                'ends_at' => $ends_at->format('Y-m-d H:i'),
                'mechanics' => $mechanics->pluck('name')->all(),
                'service_order_id' => $service_order_id,
            ],
            'Afspraak ingepland op ' . $starts_at->format('d-m-Y H:i') . ' met ' . $mechanics->pluck('name')->implode(', ') . '.',
        );
    }

    /**
     * Somebody already booked is not somebody who is free, and the diary is the
     * only thing that knows. Checked here rather than trusted from the planning
     * answer: minutes can pass between reading availability and agreeing to it,
     * and somebody else may have taken the slot in between.
     *
     * @param  Collection<int, User>  $mechanics
     * @return array<int, string>
     */
    private function clashes($mechanics, CarbonImmutable $starts_at, CarbonImmutable $ends_at): array
    {
        $availability = app(TechnicianAvailability::class);
        $day = $starts_at->startOfDay();
        $wanted_start = $starts_at->hour * 60 + $starts_at->minute;
        $wanted_end = $wanted_start + (int) $starts_at->diffInMinutes($ends_at);

        $clashes = [];

        foreach ($mechanics as $mechanic) {
            $fits = false;

            foreach ($availability->freeSegments($mechanic->load('unavailabilities'), $day) as $segment) {
                if ($segment['start_min'] <= $wanted_start && $segment['end_min'] >= $wanted_end) {
                    $fits = true;

                    break;
                }
            }

            if (!$fits) {
                $clashes[] = $mechanic->name . ' is dan niet vrij';
            }
        }

        return $clashes;
    }

    private function moment(?string $value): ?CarbonImmutable
    {
        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
