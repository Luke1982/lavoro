<?php

namespace App\Services;

use App\Models\Event;

class StandardEmailRenderer
{
    public static function placeholders(): array
    {
        return [
            ['token' => '{{event_start_date}}', 'label' => 'Startdatum'],
            ['token' => '{{event_start_time}}', 'label' => 'Starttijd'],
            ['token' => '{{event_end_date}}', 'label' => 'Einddatum'],
            ['token' => '{{event_end_time}}', 'label' => 'Eindtijd'],
            ['token' => '{{event_name}}', 'label' => 'Naam afspraak'],
            ['token' => '{{event_location}}', 'label' => 'Locatie'],
            ['token' => '{{customer_name}}', 'label' => 'Klantnaam'],
        ];
    }

    public static function render(string $html, Event $event): string
    {
        $customer = $event->primaryCustomer();

        $replacements = [
            '{{event_start_date}}' => $event->start?->format('d-m-Y') ?? '',
            '{{event_start_time}}' => $event->start?->format('H:i') ?? '',
            '{{event_end_date}}' => $event->end?->format('d-m-Y') ?? '',
            '{{event_end_time}}' => $event->end?->format('H:i') ?? '',
            '{{event_name}}' => $event->name ?? '',
            '{{event_location}}' => $event->location ?? '',
            '{{customer_name}}' => $customer?->name ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    public static function defaultRecipient(Event $event): ?string
    {
        return $event->primaryCustomer()?->email;
    }
}
