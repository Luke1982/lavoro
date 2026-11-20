<?php

namespace App\Enums;

/**
 * Waarvoor een link zonder inlog wordt uitgegeven.
 *
 * De waarde is een sleutel die in de database staat en waarop vergeleken wordt,
 * dus geen Nederlandse zin: die zou herschreven worden en dan opent een oude
 * link niets meer.
 *
 * Een soort toevoegen is een case toevoegen en de vragen hieronder
 * beantwoorden. Verder verandert er niets aan de tabel, het model of de
 * middleware: die weten niet waar een link over gaat.
 */
enum AccessTokenPurpose: string
{
    case ticket_customer_upload = 'ticket.customer_upload';
    case quote_response = 'quote.response';

    public function label(): string
    {
        return match ($this) {
            self::ticket_customer_upload => 'Klant levert informatie aan bij een storing',
            self::quote_response => 'Klant reageert op een offerte',
        };
    }

    /** De route waar de link naartoe wijst. */
    public function routeName(): string
    {
        return match ($this) {
            self::ticket_customer_upload => 'public.ticket.upload',
            self::quote_response => 'public.quote.respond',
        };
    }

    public function ttlDays(): int
    {
        return match ($this) {
            self::ticket_customer_upload => (int) config('customerupload.token_days', 14),
            self::quote_response => 30,
        };
    }

    /**
     * Whether the link takes one answer and is then used up. Files for a storing
     * may come in several times; a yes or a no to a quote is said once, and
     * after that the link says it was answered instead of asking again.
     */
    public function answersOnce(): bool
    {
        return match ($this) {
            self::ticket_customer_upload => false,
            self::quote_response => true,
        };
    }
}
