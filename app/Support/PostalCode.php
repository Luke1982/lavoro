<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * A Dutch postal code, the way it is stored: "1234AB", whatever was typed.
 */
final class PostalCode
{
    public static function normalize(string $postal_code): string
    {
        return strtoupper(preg_replace('/\s|-/', '', $postal_code));
    }

    /**
     * Records with this postal code, also where it was stored before it was
     * normalised: imported customers still carry "1234 ab".
     */
    public static function whereSame(Builder $query, string $postal_code): Builder
    {
        return $query->whereRaw(
            "UPPER(REPLACE(REPLACE(postal_code, ' ', ''), '-', '')) = ?",
            [self::normalize($postal_code)],
        );
    }
}
