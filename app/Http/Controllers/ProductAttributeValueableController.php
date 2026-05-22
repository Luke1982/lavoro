<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductAttributeValueable;
use App\Http\Requests\ProductAttributeValueableStoreRequest;

class ProductAttributeValueableController extends Controller
{
    public function store(ProductAttributeValueableStoreRequest $request)
    {
        $data = $request->validated();

        ProductAttributeValueable::where([
            'productattributevalueable_type' => Product::class,
            'productattributevalueable_id'   => $data['product_id'],
            'product_attribute_id'           => $data['product_attribute_id'],
        ])->delete();

        if (! empty($data['product_attribute_value_id'])) {
            ProductAttributeValueable::create([
                'product_attribute_value_id'     => $data['product_attribute_value_id'],
                'productattributevalueable_type' => Product::class,
                'productattributevalueable_id'   => $data['product_id'],
                'product_attribute_id'           => $data['product_attribute_id'],
            ]);
        }

        return redirect()->back()->with('success', 'Kenmerkwaarde opgeslagen.');
    }
}
