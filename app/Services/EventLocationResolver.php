<?php

namespace App\Services;

use App\Models\Event;

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

        return collect([$customer->address, trim($customer->postal_code . ' ' . $customer->city)])
            ->filter()
            ->implode(', ') ?: null;
    }
}
