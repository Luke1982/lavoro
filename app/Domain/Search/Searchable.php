<?php

namespace App\Domain\Search;

use App\Models\User;

/**
 * One record type the spotlight can find.
 *
 * Each implementation owns its own permission check, because what a technician
 * may find differs per type: werkbonnen narrow to the ones they execute while
 * leveranciers are all-or-nothing. Returning an empty array is the answer for
 * "not allowed" — the spotlight never says a group exists but is off limits.
 */
interface Searchable
{
    public function group(): string;

    /** @return SearchHit[] */
    public function search(User $user, string $term, int $limit): array;
}
