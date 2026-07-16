<?php

namespace App\Support;

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
}
