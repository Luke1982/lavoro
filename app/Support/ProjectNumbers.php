<?php

namespace App\Support;

/**
 * Hands out project numbers: P-2026-014, the year the project was made and a
 * counter that starts again every year.
 */
class ProjectNumbers
{
    public static function next(int $year): string
    {
        return self::format($year, YearlyCounter::next('project_number_counters', $year));
    }

    public static function format(int $year, int $sequence): string
    {
        return 'P-' . $year . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }
}
