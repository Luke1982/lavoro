<?php

namespace App\Services;

use App\Domain\Planning\Clock;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\StandardEmail;
use App\Support\AddressFormatter;
use Illuminate\Support\Str;

class StandardEmailRenderer
{
    /** Every placeholder by name, in the order the editor offers them, with the label a person picks it by. */
    private const LABELS = [
        'event_start_date' => 'Startdatum',
        'event_start_time' => 'Starttijd',
        'event_end_date' => 'Einddatum',
        'event_end_time' => 'Eindtijd',
        'event_name' => 'Naam afspraak',
        'event_type' => 'Type afspraak',
        'event_description' => 'Omschrijving afspraak',
        'event_location' => 'Locatie',
        'mechanic_names' => 'Monteur(s)',
        'customer_name' => 'Klantnaam',
        'customer_contact' => 'Contactpersoon',
        'customer_address' => 'Adres klant',
        'customer_phone' => 'Telefoon klant',
        'service_order_number' => 'Werkbonnummer',
        'service_order_description' => 'Omschrijving werkbon',
        'company_name' => 'Bedrijfsnaam',
    ];

    /** @return array<int, array{token: string, label: string}> */
    public static function placeholders(): array
    {
        return collect(self::LABELS)
            ->map(fn (string $label, string $name) => ['token' => self::token($name), 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * A standard e-mail made out for one appointment, ready to preview or send.
     *
     * The body is HTML, so what a person typed is escaped there and keeps its
     * line breaks; the subject is one line of plain text, so a value in it
     * loses its line breaks instead.
     *
     * @return array{to: ?string, subject: string, body: string}
     */
    public static function compose(StandardEmail $standard_email, Event $event): array
    {
        $values = self::values($event);

        return [
            'to' => self::defaultRecipient($event),
            'subject' => self::fill($standard_email->subject, $values, html: false),
            'body' => self::fill($standard_email->body, $values, html: true),
        ];
    }

    /** Fills the placeholders in one piece of HTML, the way a body is filled. */
    public static function render(string $template, Event $event): string
    {
        return self::fill($template, self::values($event), html: true);
    }

    /** The work order's contact person when they have an address of their own, the customer's otherwise. */
    public static function defaultRecipient(Event $event): ?string
    {
        return self::contact($event)?->email ?: $event->primaryCustomer()?->email;
    }

    /**
     * The person the mail is for: the contact picked on the first work order of
     * the appointment's customer that has one. A contact on a work order of
     * another customer is never borrowed.
     */
    private static function contact(Event $event): ?Contact
    {
        $event->loadMissing(['serviceOrders.customer', 'serviceOrders.contact', 'customers']);
        $customer = $event->primaryCustomer();

        return $event->serviceOrders
            ->first(fn (ServiceOrder $order) => $order->contact && $order->customer_id === $customer?->id)
            ?->contact;
    }

    /**
     * One pass over the template, so a value that happens to contain a token
     * is never filled in a second time.
     *
     * @param  array<string, ?string>  $values
     */
    private static function fill(string $template, array $values, bool $html): string
    {
        $replacements = collect($values)->mapWithKeys(fn (?string $value, string $name) => [
            self::token($name) => $html ? nl2br(e($value), false) : Str::squish((string) $value),
        ]);

        return strtr($template, $replacements->all());
    }

    private static function token(string $name): string
    {
        return '{{' . $name . '}}';
    }

    /** @return array<string, ?string> */
    private static function values(Event $event): array
    {
        $event->loadMissing(['eventType', 'executingUsers']);

        $contact = self::contact($event);
        $customer = $event->primaryCustomer();
        $orders = $event->serviceOrders;
        $start = Clock::toLocal($event->start);
        $end = Clock::toLocal($event->end);

        return [
            'event_start_date' => $start->format('d-m-Y'),
            'event_start_time' => $start->format('H:i'),
            'event_end_date' => $end->format('d-m-Y'),
            'event_end_time' => $end->format('H:i'),
            'event_name' => $event->name,
            'event_type' => $event->eventType?->name,
            'event_description' => $event->description,
            'event_location' => $event->display_location,
            'mechanic_names' => $event->executingUsers->pluck('name')->filter()->join(', ', ' en '),
            'customer_name' => $customer?->name,
            'customer_contact' => $contact?->full_name ?: $customer?->contactname,
            'customer_address' => AddressFormatter::format(
                $customer?->address,
                $customer?->postal_code,
                $customer?->city,
            ),
            'customer_phone' => $customer?->phone ?: $customer?->mobile,
            'service_order_number' => $orders->pluck('number')->join(', '),
            'service_order_description' => $orders->pluck('description')->filter()->unique()->join("\n\n"),
            'company_name' => Company::main()?->name ?? config('app.name'),
        ];
    }
}
