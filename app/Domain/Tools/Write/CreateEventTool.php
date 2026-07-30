<?php

namespace App\Domain\Tools\Write;

use App\Actions\Appointments\AppointmentAssignment;
use App\Actions\Appointments\CreateAppointmentAction;
use App\Actions\Appointments\NewAppointment;
use App\Domain\Planning\TechnicianAvailability;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Location;
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
class CreateEventTool implements Confirmable, Tool
{
    public static function name(): string
    {
        return 'create_event';
    }

    public function description(): string
    {
        return 'Plant een afspraak in de agenda voor een of meer monteurs. '
            . 'Gebruik dit pas als datum, tijd, duur en monteurs allemaal bekend zijn — vraag er anders '
            . 'eerst naar. Zijn ze bekend, roep de tool dan meteen aan: er wordt nog niets gewijzigd, je '
            . 'krijgt terug dat er bevestiging nodig is en het systeem legt de knop aan de gebruiker voor. '
            . 'Vraag dus niet zelf eerst in tekst om toestemming; dan gebeurt er namelijk helemaal niets. '
            . 'Moet er ook een werkbon bij, gebruik dan create_service_order_for_customer_id in deze tool '
            . 'in plaats van create_service_order apart: apart aangemaakt komen ze los van elkaar te staan.';
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
                    'description' => 'Een bestaande werkbon waar de afspraak bij hoort.',
                ],
                'create_service_order_for_customer_id' => [
                    'type' => 'integer',
                    'description' => 'Moet er nog een werkbon bij gemaakt worden, geef dan hier de klant op. '
                        . 'De werkbon en de afspraak worden dan in één keer aangemaakt en aan elkaar gekoppeld. '
                        . 'Gebruik dit in plaats van create_service_order apart aanroepen: los aangemaakt '
                        . 'staan ze na bevestiging niet aan elkaar vast.',
                ],
                'location_id' => [
                    'type' => 'integer',
                    'description' => 'De locatie van de klant waar het werk gebeurt. Heeft de klant meer '
                        . 'dan één locatie, vraag dan welke: het adres van de klant is niet per se waar '
                        . 'de monteur moet zijn.',
                ],
                'service_order_description' => [
                    'type' => 'string',
                    'description' => 'Waar de nieuwe werkbon over gaat.',
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

    public function previewOf(ToolCall $call): string
    {
        $names = User::query()
            ->whereIn('id', $call->integerListArgument('user_ids'))
            ->orderBy('name')
            ->pluck('name');

        $when = $this->moment($call->stringArgument('starts_at'));

        $for_customer = $call->integerArgument('create_service_order_for_customer_id');
        $customer = $for_customer === null ? null : Customer::find($for_customer);

        return 'Afspraak inplannen'
            . ($when ? ' op ' . $when->format('d-m-Y H:i') : '')
            . ($names->isNotEmpty() ? ' met ' . $names->implode(', ') : '')
            . (blank($call->stringArgument('subject')) ? '' : ' — ' . $call->stringArgument('subject'))
            . ($customer ? ', met een nieuwe werkbon voor ' . $customer->name : '')
            . ($call->integerArgument('service_order_id') ? ', op werkbon #' . $call->integerArgument('service_order_id') : '');
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
        $for_customer_id = $call->integerArgument('create_service_order_for_customer_id');

        if ($service_order_id !== null && $for_customer_id !== null) {
            return ToolResult::failed(
                'Kies één van beide: koppelen aan een bestaande werkbon, of er een nieuwe bij maken.'
            );
        }

        if ($service_order_id !== null) {
            $visible = ServiceOrder::visibleTo($call->user)->whereKey($service_order_id)->exists();

            if (!$visible) {
                return ToolResult::notFound('Werkbon #' . $service_order_id);
            }
        }

        $location_id = $call->integerArgument('location_id');
        $customer = null;

        if ($for_customer_id !== null) {
            /**
             * Making a werkbon is a separate right from filling in a diary, and
             * this does both, so it has to hold both.
             */
            if (!$call->user->can('create', ServiceOrder::class)) {
                return ToolResult::denied();
            }

            $customer = Customer::find($for_customer_id);

            if ($customer === null) {
                return ToolResult::notFound('Klant #' . $for_customer_id);
            }
        }

        $event_type_id = EventType::query()->orderBy('id')->value('id');

        if ($event_type_id === null) {
            return ToolResult::failed('Er is geen afspraaksoort ingesteld.');
        }

        /**
         * The site has to belong to whoever the work is for. A real address at the
         * wrong customer is the sort of mistake that only surfaces when a van is
         * already parked outside it.
         */
        if ($location_id !== null) {
            $owner = $customer?->id ?? ServiceOrder::whereKey($service_order_id)->value('customer_id');

            if ($owner === null) {
                return ToolResult::failed('Een locatie hoort bij een klant, dus geef ook de werkbon of de klant mee.');
            }

            if (!Location::where('id', $location_id)->where('customer_id', $owner)->exists()) {
                return ToolResult::failed('Die locatie hoort niet bij deze klant.');
            }
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
                'location_id' => $location_id,
                'status' => 'Gepland',
            ],
            eventable_type: $service_order_id ? ServiceOrder::class : null,
            eventable_id: $service_order_id,
            create_service_order: $customer !== null,
            no_service_order: $service_order_id === null && $customer === null,
            customer_id: $customer?->id,
            assignment: new AppointmentAssignment(user_ids: $mechanics->pluck('id')->all()),
            service_order_description: $call->stringArgument('service_order_description')
                ?: $call->stringArgument('subject'),
        ));

        $order_id = $event->serviceOrders()->value('service_orders.id') ?? $service_order_id;

        return ToolResult::ok(
            [
                'event_id' => $event->id,
                'starts_at' => $starts_at->format('Y-m-d H:i'),
                'ends_at' => $ends_at->format('Y-m-d H:i'),
                'mechanics' => $mechanics->pluck('name')->all(),
                'service_order_id' => $order_id,
                'link' => '/serviceorders/' . $order_id,
            ],
            'Afspraak ingepland op ' . $starts_at->format('d-m-Y H:i') . ' met ' . $mechanics->pluck('name')->implode(', ')
            . ($customer !== null && $order_id ? ', op nieuwe werkbon #' . $order_id : '') . '.',
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
