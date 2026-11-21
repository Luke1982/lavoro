<?php

namespace App\Domain\Signals\Materials;

/**
 * The stock a booking moved, before and after, as the change row the material's
 * timeline reads. Every signal that moves stock reports it this way, so the stock
 * column never needs a generic entry of its own beside it.
 */
trait ReportsStock
{
    /** @return array<int, array<string, ?string>> */
    protected static function stockChanges(?float $before, ?float $after): array
    {
        if ($before === null && $after === null) {
            return [];
        }

        return [[
            'field' => 'stock',
            'label' => 'Voorraad',
            'old_value' => self::stockValue($before),
            'new_value' => self::stockValue($after),
            'old_label' => self::stockValue($before),
            'new_label' => self::stockValue($after),
        ]];
    }

    private static function stockValue(?float $stock): ?string
    {
        return $stock === null ? null : (string) $stock;
    }
}
