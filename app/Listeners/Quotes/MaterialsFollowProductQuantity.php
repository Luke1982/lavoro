<?php

namespace App\Listeners\Quotes;

use App\Domain\Signals\Quotes\QuoteLineChanged;
use App\Domain\Signals\Quotes\StandardMaterialsFollowedProduct;
use App\Domain\Signals\Signals;
use App\Models\Material;
use App\Models\Product;

/**
 * Three units instead of two means three brackets instead of two. When the
 * quantity of a product changes, every material under it that still holds the
 * standard quantity for the old number moves to the standard for the new one.
 *
 * A material somebody set by hand is left alone: if they changed the piping to
 * 12 metres, they meant 12 metres.
 */
class MaterialsFollowProductQuantity
{
    public function handle(QuoteLineChanged $signal): void
    {
        $product = array_key_exists('quantity', $signal->fields) ? $signal->line->item : null;

        if (!$product instanceof Product) {
            return;
        }

        [$before, $after] = array_map('floatval', $signal->fields['quantity']);
        $per_unit = $product->materialDefaults()->pluck('quantity', 'productable_id');
        $followed = [];

        foreach ($signal->line->children()->where('item_type', Material::class)->get() as $child) {
            $standard = $per_unit->get($child->item_id);

            if ($standard === null || abs($child->quantity - round($standard * $before, 2)) > 0.001) {
                continue;
            }

            $followed[] = ['line' => $child, 'before' => $child->quantity, 'after' => round($standard * $after, 2)];
            $child->update(['quantity' => round($standard * $after, 2)]);
        }

        if ($followed !== []) {
            Signals::dispatch(new StandardMaterialsFollowedProduct($signal->quote, $signal->line, $followed));
        }
    }
}
