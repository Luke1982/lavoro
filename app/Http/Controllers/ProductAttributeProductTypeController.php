<?php

namespace App\Http\Controllers;

use App\Models\ProductAttribute;
use App\Models\ProductType;
use App\Http\Requests\ProductAttributeProductTypeStoreRequest;
use App\Http\Requests\ProductAttributeProductTypeDestroyRequest;

class ProductAttributeProductTypeController extends Controller
{
    public function store(ProductAttributeProductTypeStoreRequest $request, ProductAttribute $productattribute, ProductType $producttype)
    {
        if (! $productattribute->productTypes()->where('product_type_id', $producttype->id)->exists()) {
            $productattribute->productTypes()->attach($producttype->id);
        }

        return redirect()->back()->with('success', 'Producttype gekoppeld.');
    }

    public function destroy(ProductAttributeProductTypeDestroyRequest $request, ProductAttribute $productattribute, ProductType $producttype)
    {
        $productattribute->productTypes()->detach($producttype->id);

        return redirect()->back()->with('success', 'Producttype ontkoppeld.');
    }

    public function sync(ProductAttributeProductTypeStoreRequest $request, ProductAttribute $productattribute)
    {
        $ids = array_filter((array) $request->input('product_type_ids', []), 'is_numeric');
        $productattribute->productTypes()->sync($ids);

        return redirect()->back()->with('success', 'Producttypen bijgewerkt.');
    }
}
