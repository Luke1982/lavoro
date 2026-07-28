<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The one address format used across the app: "Dorpsstraat 1, 1234AB Utrecht".
 *
 * Anything with an address/postal code/city — a location, a customer — formats
 * through here so the same address never renders two different ways.
 */
class AddressFormatter
{
    public static function format(?string $address, ?string $postal_code, ?string $city): ?string
    {
        $postal_and_city = trim($postal_code . ' ' . $city);

        return collect([$address, $postal_and_city])
            ->map(fn (?string $part) => trim((string) $part))
            ->filter()
            ->implode(', ') ?: null;
    }

    /**
     * The city back out of an address line: everything after the last comma, minus
     * the postal code format() puts in front of it — Dutch (1234 AB) as well as
     * the plain numeric kind the Belgian and German customers carry. Free text
     * that never went through format() gets the same treatment: a guess, but a
     * better one than printing a whole address where a city was asked for.
     */
    public static function city(?string $address_line): ?string
    {
        $tail = trim(Str::afterLast((string) $address_line, ','));

        return trim((string) preg_replace('/^(\d{4}\s*[a-z]{2}|\d{4,6})\b\s*/i', '', $tail)) ?: null;
    }
}
