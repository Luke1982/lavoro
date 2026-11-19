<?php

namespace App\Actions\Products;

use App\Domain\Signals\Products\ProductAttributeValueRemoved;
use App\Domain\Signals\Signals;
use App\Models\ProductAttributeValue;

/**
 * Takes a value out of the list of its kenmerk, and so off every product that
 * had it. Those products are read first: once the value is gone, the database
 * has already forgotten which ones they were.
 */
class RemoveProductAttributeValueAction
{
    public function handle(ProductAttributeValue $value): void
    {
        $products = $value->products()->get()->all();
        $value->delete();

        Signals::dispatch(new ProductAttributeValueRemoved($value->productAttribute, $value->value, $products));
    }
}
