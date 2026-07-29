<?php

namespace App\Domain\Search\Searchers;

use App\Domain\Search\Searchable;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchTerm;
use App\Models\Location;
use App\Models\User;

class LocationSearch implements Searchable
{
    public function group(): string
    {
        return 'Locaties';
    }

    public function search(User $user, string $term, int $limit): array
    {
        if (!$user->isAdmin() && !$user->hasPermission('location.read')) {
            return [];
        }

        $like = SearchTerm::like($term);

        return Location::query()
            ->where(fn ($q) => $q
                ->where('title', 'like', $like)
                ->orWhere('location_code', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('address', 'like', $like))
            ->with('customer:id,name')
            ->orderBy('title')
            ->limit($limit)
            ->get(['id', 'title', 'city', 'address', 'customer_id'])
            ->map(fn (Location $location) => new SearchHit(
                $this->group(),
                $location->title,
                collect([$location->customer?->name, $location->city])->filter()->implode(' · '),
                '/locations/' . $location->id,
            ))
            ->all();
    }
}
