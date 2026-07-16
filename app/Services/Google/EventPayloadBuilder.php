<?php

namespace App\Services\Google;

use App\Models\Event;
use App\Services\EventLocationResolver;

class EventPayloadBuilder
{
    public function __construct(private EventLocationResolver $location_resolver) {}

    public function build(Event $event): array
    {
        $event->loadMissing([
            'eventType',
            'linkedLocation',
            'serviceOrders.project',
            'customers',
            'serviceOrders.customer',
            'serviceOrders.linkedLocation',
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
        return $this->location_resolver->resolve($event);
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
