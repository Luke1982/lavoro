<?php

namespace App\Http\Controllers;

use App\Actions\Products\SyncProductAttributeTypesAction;
use App\Http\Requests\ProductAttributeProductTypeDestroyRequest;
use App\Http\Requests\ProductAttributeProductTypeStoreRequest;
use App\Http\Requests\ProductAttributeProductTypeSyncRequest;
use App\Models\ProductAttribute;
use App\Models\ProductType;

class ProductAttributeProductTypeController extends Controller
{
    public function __construct(private SyncProductAttributeTypesAction $types) {}

    public function store(
        ProductAttributeProductTypeStoreRequest $request,
        ProductAttribute $productattribute,
        ProductType $producttype,
    ) {
        $this->types->attach($productattribute, $producttype->id);

        return redirect()->back()->with('success', 'Producttype gekoppeld.');
    }

    public function destroy(
        ProductAttributeProductTypeDestroyRequest $request,
        ProductAttribute $productattribute,
        ProductType $producttype,
    ) {
        $this->types->detach($productattribute, $producttype->id);

        return redirect()->back()->with('success', 'Producttype ontkoppeld.');
    }

    public function sync(ProductAttributeProductTypeSyncRequest $request, ProductAttribute $productattribute)
    {
        $this->types->handle($productattribute, $request->validated('product_type_ids'));

        return redirect()->back()->with('success', 'Producttypen bijgewerkt.');
    }
}
