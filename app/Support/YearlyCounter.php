<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A counter that starts again every year, raised in the database itself in one
 * statement, so two records made at the same moment are never given the same
 * number: LAST_INSERT_ID(expr) keeps the raised value for this connection to
 * read back. The table has a year as primary key and a last_number column.
 */
final class YearlyCounter
{
    public static function next(string $table, int $year): int
    {
        DB::statement(
            'INSERT INTO ' . $table . ' (year, last_number) VALUES (?, LAST_INSERT_ID(1))'
                . ' ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            [$year],
        );

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS sequence')->sequence;
    }
}
