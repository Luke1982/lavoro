<?php

namespace App\Domain\Search;

/**
 * Matches an address the way somebody types it against the way it is stored.
 *
 * An address lives in separate columns but is typed, or pasted, as one line. So
 * the punctuation goes from both sides before comparing: "Stationsweg 45, 3011 CE
 * Rotterdam" then matches a row holding those three parts separately, and
 * "3011CE" matches a stored "3011 CE".
 */
class AddressTerm
{
    private const SEPARATORS = [' ', ',', '.', '-', '/', "'"];

    /**
     * The term with exactly what sql() strips taken out, so the two sides never
     * disagree. A term of nothing but separators becomes '-' rather than '': a
     * stripped column never holds a dash, where '' would match every row.
     */
    public static function like(string $term): string
    {
        return SearchTerm::like(mb_strtolower(str_replace(self::SEPARATORS, '', $term)) ?: '-');
    }

    /** A where clause, to be bound to like(), over one column or several read as one line. */
    public static function sql(string ...$columns): string
    {
        $expression = count($columns) === 1
            ? "lower({$columns[0]})"
            : 'lower(concat_ws(\' \', ' . implode(', ', $columns) . '))';

        foreach (self::SEPARATORS as $separator) {
            $expression = "replace({$expression}, '" . str_replace("'", "''", $separator) . "', '')";
        }

        return $expression . ' like ?';
    }
}
