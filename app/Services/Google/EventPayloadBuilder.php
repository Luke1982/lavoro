<?php

namespace App\Services\Google;

use App\Models\Event;

class EventPayloadBuilder
{
    public function build(Event $event): array
    {
        $event->loadMissing(['eventType', 'serviceOrders.customer']);

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
        if (!empty($event->location)) {
            return $event->location;
        }

        $customer = $event->serviceOrders->first()?->customer;
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
            : url('/events/' . $event->id);
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
