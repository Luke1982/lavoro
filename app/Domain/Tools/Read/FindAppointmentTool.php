<?php

namespace App\Domain\Tools\Read;

use App\Domain\Planning\Clock;
use App\Domain\Tools\Read\Concerns\ReportsTheWholeCount;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Reads the diary: what is booked, when, and with whom.
 *
 * The one thing missing for the longest time, and the omission was hard to see
 * because so much nearly answered it. Availability reads the diary but only
 * reports the gaps. The history says "Fase gewijzigd naar: Ingepland" without a
 * date, because the date is not what changed. A werkbon knows it is planned and
 * not when.
 *
 * So "wanneer gaan we dit installeren?" — about the plainest question a planner
 * has — could not be answered at all, while appointments could be created. Being
 * able to write something you cannot read back is a strange place to have
 * stopped.
 */
class FindAppointmentTool implements Tool
{
    use ReportsTheWholeCount;

    public static function name(): string
    {
        return 'find_appointments';
    }

    public function description(): string
    {
        return 'Zoekt afspraken in de agenda: wanneer iets staat, hoe lang, en welke monteurs erbij zijn. '
            . 'Gebruik dit voor vragen als "wanneer gaan we dit doen", "wat staat er morgen", '
            . '"wanneer komt de monteur" — dus voor wat er al gepland is, niet voor wie er nog vrij is.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service_order_id' => [
                    'type' => 'integer',
                    'description' => 'Alleen de afspraken die bij deze werkbon horen.',
                ],
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Alleen afspraken bij deze klant.',
                ],
                'user_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Alleen afspraken waar deze monteurs op staan.',
                ],
                'from' => [
                    'type' => 'string',
                    'description' => 'Vanaf deze datum, als JJJJ-MM-DD. Standaard vandaag.',
                ],
                'until' => [
                    'type' => 'string',
                    'description' => 'Tot en met deze datum, als JJJJ-MM-DD.',
                ],
                'include_past' => [
                    'type' => 'boolean',
                    'description' => 'Ook afspraken die al geweest zijn. Gebruik dit bij vragen over '
                        . 'wanneer er voor het laatst iemand was.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return true;
    }

    /** Een datum of een werkbon uit de vraag halen en de agenda erop nazien. */
    public static function difficulty(): int
    {
        return 3;
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
        $from = $call->dateArgument('from');
        $until = $call->dateArgument('until');

        foreach (['from' => $from, 'until' => $until] as $key => $date) {
            if ($call->wasGiven($key) && $date === null) {
                return ToolResult::failed(
                    'Geef ' . $key . ' als een echte datum in de vorm JJJJ-MM-DD, bijvoorbeeld ' . Clock::today() . '.'
                );
            }
        }

        $query = Event::query()
            ->visibleTo($call->user)
            ->with([
                'eventType:id,name',
                'executingUsers:id,name',
                'serviceOrders:id,description',
                'customers:id,name',
                'linkedLocation:id,title,address,postal_code,city',
            ]);

        $order_id = $call->integerArgument('service_order_id');

        if ($order_id !== null) {
            $query->whereHas('serviceOrders', fn ($q) => $q->where('service_orders.id', $order_id));
        }

        $customer_id = $call->integerArgument('customer_id');

        if ($customer_id !== null) {
            /** Attached to the customer directly, or through the werkbon it is for. */
            $query->where(fn ($outer) => $outer
                ->whereHas('customers', fn ($q) => $q->where('customers.id', $customer_id))
                ->orWhereHas('serviceOrders', fn ($q) => $q->where('service_orders.customer_id', $customer_id)));
        }

        $user_ids = $call->integerListArgument('user_ids');

        /**
         * Given names instead of numbers, this used to filter on nothing and hand
         * back the whole diary — an answer about everybody to a question about two
         * people, and no sign anything had gone wrong.
         */
        if ($call->wasGiven('user_ids') && $user_ids === []) {
            return ToolResult::failed('Geef user_ids als nummers. Zoek de monteurs eerst op als je alleen namen hebt.');
        }

        if ($user_ids !== []) {
            $query->whereHas('executingUsers', fn ($q) => $q->whereIn('users.id', $user_ids));
        }

        /**
         * Ahead only unless asked otherwise. "Wanneer gaan we dit doen" is about
         * what is coming; a diary answered from the beginning of time buries it.
         */
        if (!$call->argument('include_past') && $from === null) {
            $query->where('end', '>=', Clock::startOfLocalDay(CarbonImmutable::now()));
        }

        if ($from !== null) {
            $query->where('end', '>=', Clock::startOfLocalDay($from));
        }

        if ($until !== null) {
            $query->where('start', '<', Clock::startOfLocalDay($until->addDay()));
        }

        $limit = (int) config('assistant.max_results', 25);
        $matching = clone $query;

        $events = $query
            ->orderBy('start')
            ->limit($limit)
            ->get();

        $rows = $events->map(fn (Event $event) => [
            'event_id' => $event->id,
            /** On the clock somebody reads it on, not the one it is stored in. */
            'date' => Clock::toLocal($event->start)->toDateString(),
            'from' => Clock::toLocal($event->start)->format('H:i'),
            'until' => Clock::toLocal($event->end)->format('H:i'),
            'what' => $event->name,
            'type' => $event->eventType?->name,
            'status' => $event->status,
            'mechanics' => $event->executingUsers->pluck('name')->all(),
            'service_order_id' => $event->serviceOrders->first()?->id,
            'customer' => $event->serviceOrders->first()?->customer?->name ?? $event->customers->first()?->name,
            /** Where somebody has to be, which is not always the customer's own address. */
            'where' => $event->linkedLocation?->addressLine() ?: $event->location,
        ])->all();

        if ($rows === []) {
            return ToolResult::ok(
                ['appointments' => [], 'note' => 'Geen afspraken gevonden. Dat kan betekenen dat er niets '
                    . 'gepland staat, of dat het al geweest is — gebruik include_past om ook terug te kijken.'],
                'Geen afspraken gevonden.',
            );
        }

        return $this->answerWithCount(['appointments' => $rows], count($rows), $matching, $limit, 'afspraken');
    }
}
