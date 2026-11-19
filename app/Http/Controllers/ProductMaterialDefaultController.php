<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\RemoveProductMaterialDefaultAction;
use App\Actions\Quotes\SaveProductMaterialDefaultAction;
use App\Http\Requests\ProductMaterialDefaultDestroyRequest;
use App\Http\Requests\ProductMaterialDefaultStoreRequest;
use App\Http\Requests\ProductMaterialDefaultUpdateRequest;
use App\Models\Product;
use App\Models\ProductMaterialDefault;

class ProductMaterialDefaultController extends Controller
{
    public function store(ProductMaterialDefaultStoreRequest $request, Product $product, SaveProductMaterialDefaultAction $save)
    {
        $save->execute($product, $request->validated());

        return redirect()->back()->with('success', 'Standaardmateriaal toegevoegd.');
    }

    public function update(
        ProductMaterialDefaultUpdateRequest $request,
        Product $product,
        ProductMaterialDefault $materialDefault,
        SaveProductMaterialDefaultAction $save,
    ) {
        $save->execute($product, $request->validated(), $materialDefault);

        return redirect()->back();
    }

    public function destroy(
        ProductMaterialDefaultDestroyRequest $request,
        Product $product,
        ProductMaterialDefault $materialDefault,
        RemoveProductMaterialDefaultAction $remove,
    ) {
        $remove->execute($materialDefault);

        return redirect()->back()->with('success', 'Standaardmateriaal verwijderd.');
    }
}
