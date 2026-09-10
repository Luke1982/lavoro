<?php

namespace App\Support;

/**
 * Amounts are stored in cents everywhere and shown in two ways. Those two look
 * almost the same and are not:
 *
 * - for people: 1.234,50 -- dot as thousands separator, comma as decimal
 * - for machines: 1234.50 -- no thousands separator, dot as decimal, because
 *   that is what the UBL invoice and the SEPA direct debit file prescribe
 *
 * Both used to sit through the code as loose number_format calls. Anyone
 * "improving" the one into the other quietly produced a file the bank refuses.
 * Hence two methods with a name.
 *
 * Laravel's Number::currency() does this too, but needs the intl extension.
 * That is not on the server and not worth it for this.
 */
final class Money
{
    /** As it belongs on screen and on the invoice. */
    public static function human(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }

    /**
     * As it belongs in an input field: dot as decimal, because an
     * <input type="number"> refuses a comma. Empty stays empty -- that means
     * "not set" and not "zero".
     */
    public static function input(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }

    /** AI credit is kept in millionths, finer than a cent. */
    public static function fromMicros(int $micros): int
    {
        return (int) round($micros / 10_000);
    }

    /** As a bank or accounting package reads it. */
    public static function machine(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
