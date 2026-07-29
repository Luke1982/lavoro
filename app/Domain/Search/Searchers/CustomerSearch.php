<?php

namespace App\Domain\Search\Searchers;

use App\Domain\Search\Searchable;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchTerm;
use App\Models\Customer;
use App\Models\User;

class CustomerSearch implements Searchable
{
    public function group(): string
    {
        return 'Klanten';
    }

    public function search(User $user, string $term, int $limit): array
    {
        if (!$user->isAdmin() && !$user->hasPermission('customer.read')) {
            return [];
        }

        $like = SearchTerm::like($term);

        return Customer::query()
            ->where(fn ($q) => $q
                ->where('name', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('location_code', 'like', $like))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'city', 'address'])
            ->map(fn (Customer $customer) => new SearchHit(
                $this->group(),
                $customer->name,
                $customer->city ?: $customer->address,
                '/customers/' . $customer->id,
            ))
            ->all();
    }
}
