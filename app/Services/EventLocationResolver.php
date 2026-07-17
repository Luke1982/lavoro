<?php

namespace App\Services;

use App\Models\Event;
use App\Support\AddressFormatter;

/**
 * The single definition of "where does this appointment happen".
 *
 * Every consumer (Google Calendar payload, planner export, planner UI) resolves
 * through here so the escalation order can never drift between them:
 *
 *   1. the appointment's own linked location   (most specific: picked for this visit)
 *   2. the werkbon's linked location           (still an explicit link)
 *   3. the appointment's free-text location    (a snapshot)
 *   4. the werkbon's free-text location
 *   5. the project's location
 *   6. the customer's own address
 *
 * Explicit links always beat free text.
 */
class EventLocationResolver
{
    /**
     * Every relation resolve() walks. Spread into with()/load() so the escalation
     * can't N+1, and so a new branch below only needs its relation adding here.
     * The prefix roots the paths somewhere other than the event itself ('events.').
     *
     * Both linkedLocation hops are missing on purpose: Event::$with and
     * ServiceOrder::$with already load them. Project is left unconstrained so it
     * survives being merged with a caller's own column-constrained with().
     */
    public static function relations(string $prefix = ''): array
    {
        return array_map(fn ($relation) => $prefix . $relation, [
            'serviceOrders.customer',
            'serviceOrders.project',
            'customers',
        ]);
    }

    public function resolve(Event $event): ?string
    {
        if ($event->location_id) {
            return $event->linkedLocation?->addressLine();
        }

        $order = $event->serviceOrders->first();

        if ($order?->location_id) {
            return $order->linkedLocation?->addressLine();
        }

        if (!empty($event->location)) {
            return $event->location;
        }

        if (!empty($order?->execution_location)) {
            return $order->execution_location;
        }

        if (!empty($order?->project?->location)) {
            return $order->project->location;
        }

        return $this->customerAddress($event);
    }

    private function customerAddress(Event $event): ?string
    {
        $customer = $event->primaryCustomer();

        if (!$customer) {
            return null;
        }

        return AddressFormatter::format($customer->address, $customer->postal_code, $customer->city);
    }
}
