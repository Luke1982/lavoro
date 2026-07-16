<?php

namespace App\Services\Google;

use App\Models\Event;

class EventPayloadBuilder
{
    public function build(Event $event): array
    {
        $event->loadMissing([
            'eventType',
            'linkedLocation',
            'serviceOrders.project',
            'serviceOrders.customer',
            'serviceOrders.location',
            'executingUsers',
        ]);

        return [
            'summary' => $this->buildSummary($event),
            'description' => $this->buildDescription($event),
            'location' => $this->buildLocation($event),
            'start' => $this->buildDateTime($event->start, $event->end, true),
            'end' => $this->buildDateTime($event->start, $event->end, false),
        ];
    }

    private function buildLocation(Event $event): ?string
    {
        /**
         * A location linked to this appointment is the most specific choice
         * there is — the planner picked it for this visit — so it wins outright.
         */
        if ($event->location_id) {
            return $event->resolved_location;
        }

        $service_order = $event->serviceOrders->first();

        /**
         * Then a location linked to the werkbon: still an explicit link, and it
         * beats the event's free-text location, which is only a snapshot taken
         * when the event was planned.
         */
        if ($service_order?->location_id) {
            return $service_order->resolved_location;
        }

        if (!empty($event->location)) {
            return $event->location;
        }

        if (!empty($service_order?->resolved_location)) {
            return $service_order->resolved_location;
        }

        if (!empty($service_order?->project?->location)) {
            return $service_order->project->location;
        }

        $customer = $service_order?->customer;
        if ($customer) {
            $parts = array_filter([
                $customer->address,
                trim($customer->postal_code . ' ' . $customer->city),
            ]);
            if ($parts) {
                return implode(', ', $parts);
            }
        }

        return null;
    }

    private function buildSummary(Event $event): string
    {
        $type = $event->eventType?->name;
        $service_order = $event->serviceOrders->first();
        $customer_name = $service_order?->customer?->name;
        $order_id = $service_order?->id;

        $parts = [$type ?? $event->name ?? '(geen titel)'];

        if ($customer_name) {
            $parts[] = 'bij ' . $customer_name;
        }

        if ($order_id) {
            $parts[] = 'op werkbon ' . $order_id;
        }

        return implode(' ', $parts);
    }

    private function buildDescription(Event $event): string
    {
        $parts = [];

        if (!empty($event->description)) {
            $parts[] = $event->description;
        }

        $service_order = $event->serviceOrders->first();
        $link = $service_order
            ? url('/serviceorders/' . $service_order->id)
            : url('/planner?' . http_build_query([
                'gotodate' => $event->start->format('Y-m-d'),
                'highlightevent' => $event->id,
                'executing_user_ids' => $event->executingUsers->pluck('id')->implode(','),
            ]));
        $parts[] = '— Bekijk in Lavoro: ' . $link;

        return implode("\n\n", $parts);
    }

    private function buildDateTime(\DateTimeInterface $start, \DateTimeInterface $end, bool $is_start): array
    {
        $is_all_day = $start->format('H:i:s') === '00:00:00'
            && $end->format('H:i:s') === '00:00:00'
            && $end > $start;

        $dt = $is_start ? $start : $end;

        if ($is_all_day) {
            return ['date' => $dt->format('Y-m-d')];
        }

        return [
            'dateTime' => $dt->format(\DateTimeInterface::RFC3339),
            'timeZone' => 'Europe/Amsterdam',
        ];
    }
}
