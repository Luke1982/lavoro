<?php

namespace App\Services\Google;

use App\Models\Event;

class EventPayloadBuilder
{
    public function build(Event $event): array
    {
        return [
            'summary' => $event->name ?? '(geen titel)',
            'description' => $this->buildDescription($event),
            'location' => $event->location,
            'start' => $this->buildDateTime($event->start, $event->end, true),
            'end' => $this->buildDateTime($event->start, $event->end, false),
        ];
    }

    private function buildDescription(Event $event): string
    {
        $parts = [];

        if (!empty($event->description)) {
            $parts[] = $event->description;
        }

        $event->loadMissing('serviceOrders.customer');
        foreach ($event->serviceOrders as $service_order) {
            $line = 'Service order #' . $service_order->id;
            if ($service_order->customer) {
                $line .= ' — ' . $service_order->customer->name;
            }
            if (!empty($service_order->description)) {
                $line .= "\n" . $service_order->description;
            }
            $parts[] = $line;
        }

        $deep_link = url('/events/' . $event->id);
        $parts[] = "\n— Bekijk in Lavoro: " . $deep_link;

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
