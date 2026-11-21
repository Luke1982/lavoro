<?php

namespace App\Http\Controllers;

use App\Actions\Products\ProductComponentAction;
use App\Http\Requests\ProductableDestroyRequest;
use App\Http\Requests\ProductableStoreRequest;
use App\Http\Requests\ProductableUpdateRequest;
use App\Models\Product;
use App\Models\Productable;

class ProductableController extends Controller
{
    public function store(ProductableStoreRequest $request, ProductComponentAction $components)
    {
        $v = $request->validated();

        $exists = Productable::where('product_id', $v['product_id'])
            ->where('productable_type', Product::class)
            ->where('productable_id', $v['child_product_id'])
            ->exists();

        if ($exists) {
            return redirect()->back()->with('info', 'Dit product is al gekoppeld.');
        }

        $components->add(
            Product::findOrFail($v['product_id']),
            Product::findOrFail($v['child_product_id']),
            $request->safe()->only(['product_relation_id', 'quantity', 'flex_quantity', 'is_required']),
        );

        return redirect()->back()->with('success', 'Gerelateerd product toegevoegd.');
    }

    public function update(ProductableUpdateRequest $request, Productable $productable, ProductComponentAction $components)
    {
        $components->change($productable, $request->validated());

        return redirect()->back()->with('success', 'Productrelatie bijgewerkt.');
    }

    public function destroy(ProductableDestroyRequest $request, Productable $productable, ProductComponentAction $components)
    {
        $components->remove($productable);

        return redirect()->back()->with('success', 'Productrelatie verwijderd.');
    }
}
