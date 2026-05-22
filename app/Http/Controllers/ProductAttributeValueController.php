<?php

namespace App\Http\Controllers;

use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Http\Requests\ProductAttributeValueStoreRequest;
use App\Http\Requests\ProductAttributeValueUpdateRequest;
use App\Http\Requests\ProductAttributeValueDestroyRequest;

class ProductAttributeValueController extends Controller
{
    public function store(ProductAttributeValueStoreRequest $request, ProductAttribute $productattribute)
    {
        $productattribute->values()->create(['value' => $request->validated()['value']]);

        return redirect()->back()->with('success', 'Waarde toegevoegd.');
    }

    public function update(ProductAttributeValueUpdateRequest $request, ProductAttributeValue $productattributevalue)
    {
        $productattributevalue->update($request->validated());

        return redirect()->back()->with('success', 'Waarde bijgewerkt.');
    }

    public function destroy(ProductAttributeValueDestroyRequest $request, ProductAttributeValue $productattributevalue)
    {
        $productattributevalue->delete();

        return redirect()->back()->with('success', 'Waarde verwijderd.');
    }
}
