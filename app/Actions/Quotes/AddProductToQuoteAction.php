<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteGuard;
use App\Domain\Quotes\QuoteLineDrafts;
use App\Domain\Signals\Quotes\QuoteProductAdded;
use App\Domain\Signals\Signals;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Support\Facades\DB;

/**
 * Offers a product, with the materials the person chose in the question that
 * came up: those set to be asked or suggested, each in the quantity they gave.
 * A choice of a material that is not asked about is ignored; the request has
 * already refused one that is not this product's.
 *
 * The materials that always come along are not added here. Announcing the
 * product is enough: AddStandardMaterialsToQuotedProduct hears it and adds them,
 * so however a product reaches a quote, its standard materials follow.
 */
class AddProductToQuoteAction
{
    /**
     * @param  array<int, array{default_id: int, quantity: float|int|string}>  $chosen
     */
    public function execute(Quote $quote, Product $product, float $quantity, array $chosen = []): QuoteLine
    {
        QuoteGuard::editable($quote);

        return DB::transaction(function () use ($quote, $product, $quantity, $chosen) {
            $line = $quote->addLine(QuoteLineDrafts::forProduct($product, $quantity));
            $defaults = $product->materialDefaults()->with('material.usageUnit')->get()->keyBy('id');
            $materials = [];

            foreach ($chosen as $choice) {
                $default = $defaults->get($choice['default_id']);

                if ($default?->prompt->isAsked()) {
                    $materials[] = $line->addChild(QuoteLineDrafts::forMaterial($default->material, (float) $choice['quantity']));
                }
            }

            Signals::dispatch(new QuoteProductAdded($quote, $line, $product, $materials));

            return $line;
        });
    }
}
