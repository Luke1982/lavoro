<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * A broken database rule, said in a way a user can read.
 *
 * Every unique index, foreign key and NOT NULL column in the schema is a 500
 * waiting to happen: the validation rule that should have caught it first is
 * not always there, and no rule catches two people saving the same value at the
 * same moment. This maps the driver's error back onto the column it came from,
 * so the request can end in a notification instead of a stack trace.
 *
 * Reads MySQL (production) and SQLite (tests) alike. Anything it does not
 * recognise returns null and keeps bubbling up as the real error it is.
 */
class DatabaseErrorMessage
{
    /**
     * The errors that are one column's fault: what to say when the driver names
     * that column, and what to say when it leaves the column nameless.
     */
    private const COLUMN_COMPLAINTS = [
        [[1048, 1364], 'is verplicht.', 'Er ontbreekt een verplichte waarde.'],
        [[1406], 'is te lang.', 'Een van de ingevulde waarden is te lang.'],
        [[1264], 'is buiten het toegestane bereik.', 'Een ingevulde waarde valt buiten het toegestane bereik.'],
        [[1265, 1292, 1366], 'heeft een ongeldige waarde.', 'Een van de ingevulde waarden is ongeldig.'],
    ];

    private function __construct(
        public readonly string $message,
        public readonly ?string $field = null,
    ) {}

    public static function for(QueryException $exception): ?self
    {
        $message = self::driverMessage($exception);
        $sql = $exception->getSql();
        $errno = self::errno($exception, $message, $sql);

        if ($errno === 1062) {
            return self::duplicate($message, $sql);
        }

        if ($errno === 1451) {
            return new self('Dit item wordt nog ergens gebruikt en kan daarom niet verwijderd worden.');
        }

        if ($errno === 1452) {
            return self::missingReference($message);
        }

        foreach (self::COLUMN_COMPLAINTS as [$errnos, $says, $whenNameless]) {
            if (in_array($errno, $errnos, true)) {
                return self::aboutColumn($message, $says, $whenNameless);
            }
        }

        return null;
    }

    /**
     * SQLite answers 19 for every broken constraint and says in words which one
     * it was; MySQL has a number per kind. Reading those words back into MySQL's
     * numbers leaves the rest of this class one vocabulary to speak.
     *
     * A delete can only ever have failed on the row that others point at, which
     * is the one thing MySQL says (1451 against 1452) and SQLite does not.
     */
    private static function errno(QueryException $exception, string $message, string $sql): ?int
    {
        $errno = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : null;

        if ($errno !== 19) {
            return $errno;
        }

        return match (true) {
            str_contains($message, 'UNIQUE constraint failed') => 1062,
            str_contains($message, 'NOT NULL constraint failed') => 1048,
            str_contains($message, 'FOREIGN KEY constraint failed') => self::isDelete($sql) ? 1451 : 1452,
            default => $errno,
        };
    }

    /**
     * The driver's own message, never the one the QueryException composes: that
     * one has the bindings pasted onto the end, and the bindings hold whatever
     * a user cared to type — including something shaped like an index name.
     */
    private static function driverMessage(QueryException $exception): string
    {
        $message = $exception->getPrevious()?->getMessage();

        return $message ?: Str::before($exception->getMessage(), ' (Connection: ');
    }

    private static function duplicate(string $message, string $sql): self
    {
        $columns = self::duplicateColumns($message, $sql);

        if ($columns === []) {
            return new self('Er bestaat al een item met deze gegevens.');
        }

        if (count($columns) === 1) {
            return new self(Str::ucfirst(self::label($columns[0])) . ' is al in gebruik.', $columns[0]);
        }

        $labels = collect($columns)
            ->map(fn (string $column) => self::label($column))
            ->join(', ', ' en ');

        return new self('Deze combinatie van ' . $labels . ' bestaat al.', $columns[0]);
    }

    /**
     * SQLite names the columns of the index it tripped over; MySQL names only
     * the index itself, which has to be spelled back out into columns.
     *
     * @return array<int, string>
     */
    private static function duplicateColumns(string $message, string $sql): array
    {
        if (preg_match('/UNIQUE constraint failed: ([a-z0-9_., ]+)/i', $message, $matches)) {
            return collect(explode(',', $matches[1]))
                ->map(fn (string $column) => Str::afterLast(trim($column), '.'))
                ->filter()
                ->values()
                ->all();
        }

        if (!preg_match("/for key '([^']+)'/i", $message, $matches)) {
            return [];
        }

        $index = Str::afterLast($matches[1], '.');

        return self::spellOutIndex(Str::beforeLast($index, '_unique'), $sql);
    }

    /**
     * The columns behind an index name, peeled off one at a time against the
     * columns the failing statement touched, longest first — so that
     * "serial_number_product_id" reads as two columns and not as three.
     *
     * Nothing at all when the name does not come apart cleanly: an index with a
     * hand-picked short name holds no clue about its columns, and a half-guessed
     * field name is worse than none.
     *
     * @return array<int, string>
     */
    private static function spellOutIndex(string $index, string $sql): array
    {
        $identifiers = self::identifiers($sql);
        $table = (string) array_shift($identifiers);
        $rest = Str::startsWith($index, $table . '_') ? Str::after($index, $table . '_') : $index;
        $candidates = collect($identifiers)->sortByDesc(fn (string $column) => strlen($column));
        $columns = [];

        while ($rest !== '') {
            $column = $candidates->first(
                fn (string $candidate) => $rest === $candidate || str_starts_with($rest, $candidate . '_')
            );

            if (!$column) {
                return [];
            }

            $columns[] = $column;
            $rest = ltrim(Str::after($rest, $column), '_');
        }

        return $columns;
    }

    /**
     * Every quoted name in the statement: its table first, then the columns it
     * touched, in the order it touched them.
     *
     * @return array<int, string>
     */
    private static function identifiers(string $sql): array
    {
        preg_match_all('/[`"]([a-z0-9_]+)[`"]/i', $sql, $matches);

        return array_values(array_unique($matches[1]));
    }

    private static function isDelete(string $sql): bool
    {
        return str_starts_with(strtolower(ltrim($sql)), 'delete');
    }

    private static function missingReference(string $message): self
    {
        preg_match('/FOREIGN KEY \(`?([a-z0-9_]+)`?\)/i', $message, $matches);
        $column = $matches[1] ?? null;

        return $column
            ? new self(Str::ucfirst(self::label($column)) . ' bestaat niet (meer).', $column)
            : new self('Een van de gekoppelde gegevens bestaat niet (meer).');
    }

    private static function aboutColumn(string $message, string $says, string $whenNameless): self
    {
        $column = self::columnName($message);

        return $column
            ? new self(Str::ucfirst(self::label($column)) . ' ' . $says, $column)
            : new self($whenNameless);
    }

    private static function columnName(string $message): ?string
    {
        if (preg_match("/(?:column|field) '([^']+)'/i", $message, $matches)) {
            return $matches[1];
        }

        return preg_match('/constraint failed: [a-z0-9_]+\.([a-z0-9_]+)/i', $message, $matches)
            ? $matches[1]
            : null;
    }

    /**
     * The name a user knows the column by, from the same list the regular
     * validation messages read from.
     */
    private static function label(string $column): string
    {
        $key = 'validation.attributes.' . $column;
        $label = trans($key);

        if (is_string($label) && $label !== $key) {
            return $label;
        }

        return str_replace('_', ' ', Str::endsWith($column, '_id') ? Str::beforeLast($column, '_id') : $column);
    }
}
