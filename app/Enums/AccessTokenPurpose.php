<?php

namespace App\Enums;

/**
 * Waarvoor een link zonder inlog wordt uitgegeven.
 *
 * De waarde is een sleutel die in de database staat en waarop vergeleken wordt,
 * dus geen Nederlandse zin: die zou herschreven worden en dan opent een oude
 * link niets meer.
 *
 * Een soort toevoegen is een case toevoegen en de drie vragen hieronder
 * beantwoorden. Verder verandert er niets aan de tabel, het model of de
 * middleware: die weten niet waar een link over gaat.
 */
enum AccessTokenPurpose: string
{
    case ticket_customer_upload = 'ticket.customer_upload';

    public function label(): string
    {
        return match ($this) {
            self::ticket_customer_upload => 'Klant levert informatie aan bij een storing',
        };
    }

    /** De route waar de link naartoe wijst. */
    public function routeName(): string
    {
        return match ($this) {
            self::ticket_customer_upload => 'public.ticket.upload',
        };
    }

    public function ttlDays(): int
    {
        return match ($this) {
            self::ticket_customer_upload => (int) config('customerupload.token_days', 14),
        };
    }
}
