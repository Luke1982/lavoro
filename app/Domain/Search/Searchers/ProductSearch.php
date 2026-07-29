<?php

namespace App\Domain\Search\Searchers;

use App\Domain\Search\Searchable;
use App\Domain\Search\SearchHit;
use App\Domain\Search\SearchTerm;
use App\Models\Product;
use App\Models\User;

class ProductSearch implements Searchable
{
    public function group(): string
    {
        return 'Producten';
    }

    public function search(User $user, string $term, int $limit): array
    {
        $like = SearchTerm::like($term);

        return Product::query()
            ->visibleTo($user)
            ->where(fn ($q) => $q
                ->where('model', 'like', $like)
                ->orWhere('part_no', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhereHas('brand', fn ($bq) => $bq->where('name', 'like', $like)))
            ->with(['brand:id,name', 'productType:id,name'])
            ->orderBy('model')
            ->limit($limit)
            ->get(['id', 'model', 'part_no', 'brand_id', 'product_type_id'])
            ->map(fn (Product $product) => new SearchHit(
                $this->group(),
                $product->display_name,
                collect([$product->productType?->name, $product->part_no])->filter()->implode(' · '),
                '/products/' . $product->id,
            ))
            ->all();
    }
}
