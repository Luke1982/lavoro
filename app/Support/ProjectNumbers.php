<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Hands out project numbers: P-2026-014, the year the project was made and a
 * counter that starts again every year.
 *
 * The counter is raised in the database itself, in one statement, so two people
 * creating a project at the same moment can never be given the same number:
 * LAST_INSERT_ID(expr) keeps the raised value for this connection to read back.
 */
class ProjectNumbers
{
    public static function next(int $year): string
    {
        DB::statement(
            'INSERT INTO project_number_counters (year, last_number) VALUES (?, LAST_INSERT_ID(1))'
                . ' ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            [$year],
        );

        $sequence = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS sequence')->sequence;

        return self::format($year, $sequence);
    }

    public static function format(int $year, int $sequence): string
    {
        return 'P-' . $year . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }
}
