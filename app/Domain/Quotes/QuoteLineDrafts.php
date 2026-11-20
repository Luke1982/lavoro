<?php

namespace App\Domain\Quotes;

use App\Enums\QuoteLineKinds;
use App\Models\Material;
use App\Models\Product;
use App\Models\Quote;

/**
 * What a line copies from the catalogue at the moment it is offered: the name,
 * the description under it, the prices. The quote keeps that copy: a catalogue
 * price changed later does not change a quote the customer already holds. The
 * VAT is the rate the quote starts its lines with.
 */
final class QuoteLineDrafts
{
    /** @return array<string, mixed> */
    public static function forProduct(Quote $quote, Product $product, float $quantity): array
    {
        $product->loadMissing('brand');

        return [
            'kind' => QuoteLineKinds::product->value,
            'item_type' => Product::class,
            'item_id' => $product->id,
            'description' => $product->display_name ?? ('Product ' . $product->id),
            'details' => $product->description,
            'quantity' => $quantity,
            'unit' => 'stuk',
            'unit_price' => (float) ($product->retail_price ?? 0),
            'cost_price' => $product->purchase_price !== null ? (float) $product->purchase_price : null,
            'vat_percent' => $quote->default_vat_percent,
        ];
    }

    /** @return array<string, mixed> */
    public static function forMaterial(Quote $quote, Material $material, float $quantity): array
    {
        $material->loadMissing('usageUnit');

        return [
            'kind' => QuoteLineKinds::material->value,
            'item_type' => Material::class,
            'item_id' => $material->id,
            'description' => $material->name,
            'details' => $material->description,
            'quantity' => $quantity,
            'unit' => $material->usageUnit?->name,
            'unit_price' => (float) ($material->price ?? 0),
            'cost_price' => $material->cost_price !== null ? (float) $material->cost_price : null,
            'vat_percent' => $quote->default_vat_percent,
        ];
    }
}
