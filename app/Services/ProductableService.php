<?php

namespace App\Services;

use App\Models\Productable;
use App\Models\Product;

class ProductableService
{
    public static function requiredProductablesMap(): array
    {
        return Productable::query()
            ->where('is_required', true)
            ->where('productable_type', Product::class)
            ->with(['childProduct.brand', 'childProduct.productType', 'productRelation'])
            ->get()
            ->groupBy('product_id')
            ->map(fn($items) => $items->map(fn($p) => [
                'productable_id'   => $p->id,
                'child_product_id' => $p->productable_id,
                'name'             => $p->childProduct->brand->name
                    . ' ' . $p->childProduct->model
                    . ' (' . $p->childProduct->productType->name . ')',
                'quantity'         => $p->quantity,
                'relation_name'    => $p->productRelation?->name ?? 'Onderdeel',
            ])->values()->all())
            ->all();
    }
}
