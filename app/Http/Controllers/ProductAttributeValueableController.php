<?php

namespace App\Http\Controllers;

use App\Actions\Products\SetProductAttributeValuesAction;
use App\Http\Requests\ProductAttributeValueableStoreRequest;
use App\Models\Product;

class ProductAttributeValueableController extends Controller
{
    public function store(ProductAttributeValueableStoreRequest $request, SetProductAttributeValuesAction $set_values)
    {
        $data = $request->validated();

        $set_values->handle(
            Product::findOrFail($data['product_id']),
            [$data['product_attribute_id'] => $data['product_attribute_value_id'] ?? null],
        );

        return redirect()->back()->with('success', 'Kenmerkwaarde opgeslagen.');
    }
}
