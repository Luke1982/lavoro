<?php

namespace App\Domain\Quotes;

use App\Enums\QuoteLineKinds;
use App\Models\Material;
use App\Models\Product;

/**
 * What a line copies from the catalogue at the moment it is offered. The quote
 * keeps that copy: a catalogue price changed later does not change a quote the
 * customer already holds.
 */
final class QuoteLineDrafts
{
    /** @return array<string, mixed> */
    public static function forProduct(Product $product, float $quantity): array
    {
        $product->loadMissing('brand');

        return [
            'kind' => QuoteLineKinds::product->value,
            'item_type' => Product::class,
            'item_id' => $product->id,
            'description' => $product->display_name ?? ('Product ' . $product->id),
            'quantity' => $quantity,
            'unit' => 'stuk',
            'unit_price' => (float) ($product->retail_price ?? 0),
            'cost_price' => $product->purchase_price !== null ? (float) $product->purchase_price : null,
            'vat_percent' => VatRates::DEFAULT,
        ];
    }

    /** @return array<string, mixed> */
    public static function forMaterial(Material $material, float $quantity): array
    {
        $material->loadMissing('usageUnit');

        return [
            'kind' => QuoteLineKinds::material->value,
            'item_type' => Material::class,
            'item_id' => $material->id,
            'description' => $material->name,
            'quantity' => $quantity,
            'unit' => $material->usageUnit?->name,
            'unit_price' => (float) ($material->price ?? 0),
            'cost_price' => $material->cost_price !== null ? (float) $material->cost_price : null,
            'vat_percent' => VatRates::DEFAULT,
        ];
    }
}
