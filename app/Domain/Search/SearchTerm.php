<?php

namespace App\Domain\Search;

/**
 * Turns what somebody typed into a LIKE pattern.
 *
 * The wildcards have to be escaped or the search box quietly speaks SQL: typing
 * "50%" would match every row rather than the ones containing "50%", and an
 * underscore in a part number would match any character at all.
 */
class SearchTerm
{
    public static function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }
}
