<?php

namespace App\Services;

use App\Models\Ticket;

/**
 * De woorden van de informatie-aanvraag: het onderwerp, de openingstekst en de
 * namen van wat je kunt vragen.
 *
 * De lijst met wat gevraagd wordt staat hier met opzet niet in de tekst. Die
 * wordt in het scherm samengesteld, waar de knoppen staan waarmee je hem
 * verandert, en de knop naar de aanleverpagina zet de mailsjabloon eronder. Wat
 * hier gemaakt wordt is precies wat een collega mag herschrijven voordat hij
 * verstuurt.
 */
class TicketInfoRequestRenderer
{
    /** @var array<string, string> */
    public const REQUESTABLE = [
        'photos' => "foto's van de storing",
        'videos' => "video's van de storing",
        'other' => 'andere aanvullende informatie',
    ];

    /** Waar de knoppen op staan als er niets gekozen is. */
    public const DEFAULT_REQUESTED = ['photos', 'videos'];

    public static function subject(): string
    {
        return 'Wij ontvangen graag extra informatie over uw storing';
    }

    public static function body(Ticket $ticket): string
    {
        $serial = self::serial($ticket);

        return '<p>Beste ' . e(self::customerName($ticket)) . ',</p>'
            . '<p>Wij ontvingen uw storingsmelding inzake ' . e(self::machine($ticket))
            . ($serial === null ? '' : ' met serienummer ' . e($serial)) . '. '
            . 'Vervelend om te horen dat u problemen heeft! Wij willen dit graag snel en goed '
            . 'oplossen. Om de oorzaak beter te kunnen inschatten, vragen wij u aanvullende '
            . 'informatie aan te leveren. Gebruik onderstaande knop om naar een speciale pagina '
            . 'te gaan, waar u het volgende kunt aanleveren:</p>';
    }

    public static function defaultRecipient(Ticket $ticket): ?string
    {
        return $ticket->asset?->resolvedCustomer()?->email ?: null;
    }

    /** @return array<int, array<string, string>> */
    public static function options(): array
    {
        return array_map(
            fn (string $key, string $label) => ['key' => $key, 'label' => $label],
            array_keys(self::REQUESTABLE),
            array_values(self::REQUESTABLE),
        );
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    public static function labelsFor(array $keys): array
    {
        return array_values(array_filter(array_map(
            fn ($key) => is_string($key) ? (self::REQUESTABLE[$key] ?? null) : null,
            $keys,
        )));
    }

    public static function customerName(Ticket $ticket): string
    {
        return $ticket->asset?->resolvedCustomer()?->name ?: 'klant';
    }

    /**
     * Merk en model als die er zijn, anders het soort machine, anders iets
     * neutraals: de zin moet lopen ook als de catalogus mager gevuld is.
     */
    public static function machine(Ticket $ticket): string
    {
        $product = $ticket->asset?->product;

        $named = trim(implode(' ', array_filter([
            $product?->brand?->name,
            $product?->model,
        ])));

        return $named !== '' ? $named : ($product?->productType?->name ?: 'uw machine');
    }

    /**
     * Null en niet 'onbekend': een machine zonder serienummer hoort de zin over te
     * slaan, niet er een woord in te zetten dat de klant niets zegt.
     */
    public static function serial(Ticket $ticket): ?string
    {
        return $ticket->asset?->serial_number ?: null;
    }
}
