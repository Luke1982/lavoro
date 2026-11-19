<?php

namespace App\Support;

/**
 * Hands out quote numbers: OF-2026-014, the year the quote was made and a
 * counter that starts again every year.
 */
class QuoteNumbers
{
    public static function next(int $year): string
    {
        $sequence = YearlyCounter::next('quote_number_counters', $year);

        return 'OF-' . $year . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }
}
