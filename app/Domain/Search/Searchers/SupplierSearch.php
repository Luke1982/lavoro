<?php

namespace App\Domain\Search\Searchers;

use App\Domain\Search\Searchable;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchTerm;
use App\Models\Supplier;
use App\Models\User;

class SupplierSearch implements Searchable
{
    public function group(): string
    {
        return 'Leveranciers';
    }

    public function search(User $user, string $term, int $limit): array
    {
        if (!$user->isAdmin() && !$user->hasPermission('supplier.read')) {
            return [];
        }

        $like = SearchTerm::like($term);

        return Supplier::query()
            ->where(fn ($q) => $q
                ->where('name', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('contact_person', 'like', $like)
                ->orWhere('email', 'like', $like))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'city', 'contact_person'])
            ->map(fn (Supplier $supplier) => new SearchHit(
                $this->group(),
                $supplier->name,
                collect([$supplier->city, $supplier->contact_person])->filter()->implode(' · '),
                '/suppliers/' . $supplier->id,
            ))
            ->all();
    }
}
