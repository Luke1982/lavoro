<?php

namespace App\Actions\Appointments;

use App\Domain\Planning\Clock;
use App\Domain\Signals\ServiceOrders\ServiceOrderScheduled;
use App\Domain\Signals\Signals;
use App\Enums\EventStatusses;
use App\Models\Event;
use App\Models\ServiceOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Copies an appointment to other days: the same times, the same werkbonnen,
 * the same crew with their breaks, diverging times and plan roles, and for an
 * appointment without a werkbon the same customer. A copy is work still to be
 * done, so it starts as Gepland whatever the original got to, and a crew
 * member who has since been removed does not come along.
 *
 * All copies of one request are made in one transaction: all of them or none,
 * and what reacts after the commit -- the push to Google above all -- sees
 * each complete, crew and werkbonnen included, instead of the bare
 * appointment. Every copy is a new appointment, so whatever is hung on
 * creating one, a standard e-mail included, happens once per copy.
 */
class CopyAppointmentAction
{
    /** How far from the appointment a copy may land, in days either way. */
    public const MOST_DAYS_AWAY = 365;

    /**
     * No more copies than one request should make: each is a new appointment
     * and, with a standard e-mail on creating one, a mail to the customer.
     */
    public const MOST_COPIES = 31;

    /**
     * @param  array<int, int>  $offsets  Days to move each copy, counted on the local calendar.
     * @return array<int, Event>
     */
    public function execute(Event $event, array $offsets): array
    {
        $service_orders = $event->serviceOrders()->get();
        $customer_ids = $event->no_service_order ? $event->customers()->pluck('customers.id')->all() : [];
        $crew = $event->executingUsers()->withoutTrashed()->get();
        $crew_ids = $crew->pluck('id')->map(fn ($id) => (int) $id)->all();
        $assignment = new AppointmentAssignment(
            user_ids: $crew_ids,
            breaktimes: $crew->mapWithKeys(fn ($user) => [(int) $user->id => (int) $user->pivot->breaktime])->all(),
            user_roles: array_intersect_key($this->executingUserRoleMap($event), array_flip($crew_ids)),
            diverging_times: $crew->mapWithKeys(fn ($user) => [(int) $user->id => [
                'has_diverging_times' => (bool) $user->pivot->has_diverging_times,
                'diverging_start' => $user->pivot->diverging_start,
                'diverging_end' => $user->pivot->diverging_end,
            ]])->all(),
        );

        return DB::transaction(fn () => array_map(
            fn (int $days) => $this->copyTo($event, $days, $service_orders, $customer_ids, $assignment),
            array_values($offsets),
        ));
    }

    /**
     * @param  Collection<int, ServiceOrder>  $service_orders
     * @param  array<int, int>  $customer_ids
     */
    private function copyTo(Event $event, int $days, $service_orders, array $customer_ids, AppointmentAssignment $assignment): Event
    {
        [$start, $end] = self::windowFor($event, $days);

        $new_event = Event::create([
            'name' => $event->name,
            'description' => $event->description,
            'event_type_id' => $event->event_type_id,
            'status' => EventStatusses::planned->value,
            'start' => $start,
            'end' => $end,
            'location' => $event->location,
            'location_id' => $event->location_id,
            'is_preliminary' => $event->is_preliminary,
            'no_service_order' => $event->no_service_order,
        ]);

        foreach ($service_orders as $order) {
            $order->events()->attach($new_event->id);

            Signals::dispatch(new ServiceOrderScheduled($order, $new_event));
        }

        if ($customer_ids !== []) {
            $new_event->syncCustomers($customer_ids);
        }

        if (!$assignment->isEmpty()) {
            $new_event->syncExecutingUsers(
                $assignment->user_ids,
                $assignment->breaktimes,
                $assignment->user_roles,
                $assignment->diverging_times,
            );
        }

        return $new_event;
    }

    /**
     * How many days a copy has to move to land on this date. Counted between
     * plain calendar days on the clock the work happens on, so a copy across
     * a clock change is never a day short.
     */
    public static function offsetTo(Event $event, CarbonImmutable $date): int
    {
        $event_day = CarbonImmutable::parse(Clock::toLocal($event->start)->toDateString(), 'UTC');

        return (int) $event_day->diffInDays(CarbonImmutable::parse($date->toDateString(), 'UTC'));
    }

    /**
     * Where a copy moved this many days lands: the same wall-clock times on the
     * other day, as the moments to store.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function windowFor(Event $event, int $days): array
    {
        return [
            Clock::toLocal($event->start)->addDays($days)->utc(),
            Clock::toLocal($event->end)->addDays($days)->utc(),
        ];
    }

    /**
     * Why a copy cannot land on this date, or null when it can: never on the
     * day it is already on, and no further than a year either way. The one
     * statement of those rules, for the planner's "Naar datum…" and the
     * assistant alike.
     */
    public static function refusalFor(Event $event, CarbonImmutable $date): ?string
    {
        $offset = self::offsetTo($event, $date);

        if ($offset === 0) {
            return 'De afspraak staat al op deze dag; kies een andere datum.';
        }

        return abs($offset) > self::MOST_DAYS_AWAY ? 'Kies een datum binnen een jaar van de afspraak.' : null;
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function executingUserRoleMap(Event $event): array
    {
        return DB::table('userables')
            ->join('user_role_userable', 'userables.id', '=', 'user_role_userable.userable_id')
            ->where('userables.userable_type', $event->getMorphClass())
            ->where('userables.userable_id', $event->getKey())
            ->where('userables.type', 'executing')
            ->get(['userables.user_id', 'user_role_userable.user_role_id'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('user_role_id')->map(fn ($id) => (int) $id)->all())
            ->toArray();
    }
}
