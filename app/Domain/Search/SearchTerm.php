<?php

namespace App\Domain\Search;

/**
 * Turns what somebody typed into a LIKE pattern.
 *
 * The wildcards have to be escaped or the search box quietly speaks SQL: typing
 * "50%" would match every row rather than the ones containing "50%", and an
 * underscore in a part number would match any character at all. Not a security
 * hole — the values are bound — but a search that lies about what it found is
 * worse than one that finds nothing.
 *
 * Escaped with a backslash, which MySQL treats as the escape character by
 * default. SQLite does not, unless a query says ESCAPE explicitly, so there a
 * term containing a real per cent sign finds nothing rather than finding the row
 * that genuinely has one in its name. Both drivers end up refusing to match
 * everything, which is the half that matters; only MySQL also matches a literal
 * wildcard. Worth knowing, since the tests run on SQLite and the customers run
 * on MySQL.
 */
class SearchTerm
{
    public static function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }
}
