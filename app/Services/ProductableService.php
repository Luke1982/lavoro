<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Productable;

class ProductableService
{
    /**
     * The parts that materialise as child machines when a machine is built, keyed by the
     * product they hang under.
     *
     * A verplicht part contributes its aantal, as it always has. A part whose aantal is
     * flex contributes whatever the screen asks for, so it ships alongside with its number
     * left open. Anything else only comes along when someone attaches it by hand, and is
     * left out here rather than shipped to every machine form for nothing.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function bundlePartsMap(): array
    {
        return Productable::query()
            ->where('productable_type', Product::class)
            ->where(fn ($query) => $query->where('is_required', true)->orWhere('flex_quantity', true))
            ->with(['childProduct.brand', 'childProduct.productType', 'productRelation'])
            ->get()
            ->filter(fn (Productable $productable) => $productable->childProduct !== null)
            ->groupBy('product_id')
            ->map(fn ($items) => $items->map(fn (Productable $productable) => [
                'productable_id' => $productable->id,
                'child_product_id' => $productable->productable_id,
                'name' => $productable->childProduct->brand->name
                    . ' ' . $productable->childProduct->model
                    . ' (' . $productable->childProduct->productType->name . ')',
                'quantity' => $productable->quantity,
                'flex_quantity' => (bool) $productable->flex_quantity,
                'is_required' => (bool) $productable->is_required,
                'requires_serial' => $productable->childProduct->requiresSerial(),
                'relation_name' => $productable->productRelation?->name ?? 'Onderdeel',
            ])->values()->all())
            ->all();
    }
}
