<?php

namespace App\Http\Controllers;

use App\Actions\Products\FindOrAddProductAttributeValueAction;
use App\Actions\Products\RemoveProductAttributeValueAction;
use App\Domain\Signals\Products\ProductAttributeValueRenamed;
use App\Domain\Signals\Signals;
use App\Http\Requests\ProductAttributeValueDestroyRequest;
use App\Http\Requests\ProductAttributeValueStoreRequest;
use App\Http\Requests\ProductAttributeValueUpdateRequest;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;

class ProductAttributeValueController extends Controller
{
    public function store(
        ProductAttributeValueStoreRequest $request,
        ProductAttribute $productattribute,
        FindOrAddProductAttributeValueAction $add_value,
    ) {
        $add_value->handle($productattribute, $productattribute->valueFields($request->validated('value')));

        return redirect()->back()->with('success', 'Waarde toegevoegd.');
    }

    public function update(ProductAttributeValueUpdateRequest $request, ProductAttributeValue $productattributevalue)
    {
        $attribute = $productattributevalue->productAttribute;
        $old_label = $productattributevalue->value;

        $productattributevalue->update($attribute->valueFields($request->validated('value')));

        if ($productattributevalue->wasChanged('value')) {
            Signals::dispatch(new ProductAttributeValueRenamed(
                $attribute,
                $old_label,
                $productattributevalue->value,
                $productattributevalue->products()->get()->all(),
            ));
        }

        return redirect()->back()->with('success', 'Waarde bijgewerkt.');
    }

    public function destroy(
        ProductAttributeValueDestroyRequest $request,
        ProductAttributeValue $productattributevalue,
        RemoveProductAttributeValueAction $remove_value,
    ) {
        $remove_value->handle($productattributevalue);

        return redirect()->back()->with('success', 'Waarde verwijderd.');
    }
}
