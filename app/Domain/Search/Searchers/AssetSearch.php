<?php

namespace App\Domain\Search\Searchers;

use App\Domain\Search\Searchable;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchTerm;
use App\Models\Asset;
use App\Models\User;

class AssetSearch implements Searchable
{
    public function group(): string
    {
        return 'Machines';
    }

    public function search(User $user, string $term, int $limit): array
    {
        $like = SearchTerm::like($term);

        return Asset::query()
            ->visibleTo($user)
            ->where(fn ($q) => $q
                ->where('serial_number', 'like', $like)
                ->orWhereHas('product', fn ($pq) => $pq
                    ->where('model', 'like', $like)
                    ->orWhere('part_no', 'like', $like)
                    ->orWhereHas('brand', fn ($bq) => $bq->where('name', 'like', $like)))
                ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', $like)))
            ->with(['product:id,brand_id,model', 'product.brand:id,name', 'customer:id,name'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'serial_number', 'product_id', 'customer_id'])
            ->map(fn (Asset $asset) => new SearchHit(
                $this->group(),
                $asset->product?->display_name ?: ($asset->serial_number ?: 'Machine ' . $asset->id),
                collect([$asset->serial_number, $asset->customer?->name])->filter()->implode(' · '),
                '/assets/' . $asset->id,
            ))
            ->all();
    }
}
