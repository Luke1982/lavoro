<?php

namespace App\Support;

/**
 * A count as a person writes it in a sentence: "één contract", "3 contracten".
 * One is spelled out, so it does not read as a figure in a table.
 */
final class Counted
{
    public static function of(int $count, string $one, string $many): string
    {
        return $count === 1 ? 'één ' . $one : $count . ' ' . $many;
    }
}
