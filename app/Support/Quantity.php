<?php

namespace App\Support;

/**
 * A quantity as a person reads it: 2, 2,5 or 0,33. No trailing zeros, comma as
 * decimal, at most two decimals, which is what the columns hold.
 */
final class Quantity
{
    public static function human(float|int|string|null $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 2, ',', ''), '0'), ',');
    }
}
