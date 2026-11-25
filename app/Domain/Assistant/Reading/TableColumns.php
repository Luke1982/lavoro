<?php

namespace App\Domain\Assistant\Reading;

use App\Support\ForgetsTenantState;
use Illuminate\Support\Facades\Schema;

/**
 * The columns of a table and which of them are unique, read from the database
 * once per request rather than once per field that is checked.
 *
 * Tenant state: every customer has a database of its own, and a worker that
 * moves on to the next customer must not answer from the tables of the last.
 */
final class TableColumns implements ForgetsTenantState
{
    /** @var array<string, array<string, string>> */
    private array $columns = [];

    /** @var array<string, array<int, string>> */
    private array $unique = [];

    /**
     * Every column of the table with its database type.
     *
     * @return array<string, string>
     */
    public function of(?string $connection, string $table): array
    {
        $key = ($connection ?? '') . '|' . $table;

        if (isset($this->columns[$key])) {
            return $this->columns[$key];
        }

        $columns = [];

        foreach (Schema::connection($connection)->getColumns($table) as $column) {
            $columns[$column['name']] = strtolower($column['type_name']);
        }

        return $this->columns[$key] = $columns;
    }

    /**
     * The columns that are unique on their own: the primary key and every
     * single-column unique index.
     *
     * @return array<int, string>
     */
    public function uniqueOf(?string $connection, string $table): array
    {
        $key = ($connection ?? '') . '|' . $table;

        if (isset($this->unique[$key])) {
            return $this->unique[$key];
        }

        $unique = [];

        foreach (Schema::connection($connection)->getIndexes($table) as $index) {
            if (($index['unique'] || $index['primary']) && count($index['columns']) === 1) {
                $unique[] = $index['columns'][0];
            }
        }

        return $this->unique[$key] = $unique;
    }

    public function forgetTenantState(): void
    {
        $this->columns = [];
        $this->unique = [];
    }
}
