<?php

namespace App\Domain\Search;

use App\Domain\Search\Searchers\AssetSearch;
use App\Domain\Search\Searchers\ContactSearch;
use App\Domain\Search\Searchers\CustomerSearch;
use App\Domain\Search\Searchers\EventSearch;
use App\Domain\Search\Searchers\LocationSearch;
use App\Domain\Search\Searchers\MaterialSearch;
use App\Domain\Search\Searchers\ProductSearch;
use App\Domain\Search\Searchers\ProjectSearch;
use App\Domain\Search\Searchers\ServiceOrderSearch;
use App\Domain\Search\Searchers\SupplierSearch;
use App\Domain\Search\Searchers\TicketSearch;
use App\Models\User;

/**
 * Runs every searcher over one term and hands back the hits grouped per type.
 *
 * The order of the list is the order on screen: the things people look up all
 * day sit above the catalogue, so the first result under the cursor is usually
 * the one they wanted.
 */
class GlobalSearch
{
    private const SEARCHERS = [
        CustomerSearch::class,
        LocationSearch::class,
        ContactSearch::class,
        AssetSearch::class,
        TicketSearch::class,
        ServiceOrderSearch::class,
        ProjectSearch::class,
        EventSearch::class,
        ProductSearch::class,
        MaterialSearch::class,
        SupplierSearch::class,
    ];

    public function __construct(private readonly int $per_group = 5) {}

    /**
     * @return array<int, array{group: string, hits: array}>
     */
    public function run(User $user, string $term): array
    {
        $groups = [];

        foreach (self::SEARCHERS as $searcher_class) {
            /** @var Searchable $searcher */
            $searcher = new $searcher_class;
            $hits = $searcher->search($user, $term, $this->per_group);

            if ($hits === []) {
                continue;
            }

            $groups[] = [
                'group' => $searcher->group(),
                'hits' => array_map(fn (SearchHit $hit) => $hit->toArray(), $hits),
            ];
        }

        return $groups;
    }
}
