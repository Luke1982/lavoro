<?php

namespace App\Domain\Search\Searchers;

use App\Domain\Search\Searchable;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchTerm;
use App\Models\Material;
use App\Models\User;

class MaterialSearch implements Searchable
{
    public function group(): string
    {
        return 'Materialen';
    }

    public function search(User $user, string $term, int $limit): array
    {
        if (!$user->isAdmin() && !$user->hasPermission('material.read')) {
            return [];
        }

        $like = SearchTerm::like($term);

        return Material::query()
            ->where(fn ($q) => $q
                ->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('vendor_code', 'like', $like)
                ->orWhere('description', 'like', $like))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'code', 'description'])
            ->map(fn (Material $material) => new SearchHit(
                $this->group(),
                $material->name,
                collect([$material->code, $material->description])->filter()->implode(' · '),
                '/materials/' . $material->id,
            ))
            ->all();
    }
}
