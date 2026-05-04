<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Productable;
use App\Http\Requests\ProductableStoreRequest;
use App\Http\Requests\ProductableUpdateRequest;
use App\Http\Requests\ProductableDestroyRequest;

class ProductableController extends Controller
{
    public function store(ProductableStoreRequest $request)
    {
        $v = $request->validated();

        $exists = Productable::where('product_id', $v['product_id'])
            ->where('productable_type', Product::class)
            ->where('productable_id', $v['child_product_id'])
            ->exists();

        if ($exists) {
            return redirect()->back()->with('info', 'Dit product is al gekoppeld.');
        }

        Productable::create([
            'product_id'          => $v['product_id'],
            'productable_type'    => Product::class,
            'productable_id'      => $v['child_product_id'],
            'product_relation_id' => $v['product_relation_id'] ?? null,
            'quantity'            => $v['quantity'],
            'is_required'         => $v['is_required'] ?? false,
        ]);

        return redirect()->back()->with('success', 'Gerelateerd product toegevoegd.');
    }

    public function update(ProductableUpdateRequest $request, Productable $productable)
    {
        $productable->update($request->validated());

        return redirect()->back()->with('success', 'Productrelatie bijgewerkt.');
    }

    public function destroy(ProductableDestroyRequest $request, Productable $productable)
    {
        $productable->delete();

        return redirect()->back()->with('success', 'Productrelatie verwijderd.');
    }
}
