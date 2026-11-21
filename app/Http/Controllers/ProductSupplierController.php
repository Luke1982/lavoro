<?php

namespace App\Http\Controllers;

use App\Actions\Suppliers\SupplierLinkAction;
use App\Http\Requests\ProductSupplierDestroyRequest;
use App\Http\Requests\ProductSupplierStoreRequest;
use App\Http\Requests\ProductSupplierUpdateRequest;
use App\Models\Product;
use App\Models\Supplier;

class ProductSupplierController extends Controller
{
    public function store(ProductSupplierStoreRequest $request, Product $product, SupplierLinkAction $links)
    {
        $data = $request->validated();

        $links->link($product, Supplier::findOrFail($data['supplier_id']), [
            'article_number' => $data['article_number'] ?? null,
            'is_preferred' => $data['is_preferred'] ?? false,
        ]);

        return redirect()->back()->with('success', 'Leverancier gekoppeld.');
    }

    public function update(ProductSupplierUpdateRequest $request, Product $product, Supplier $supplier, SupplierLinkAction $links)
    {
        $links->change($product, $supplier, $request->validated());

        return redirect()->back()->with('success', 'Leverancier bijgewerkt.');
    }

    public function destroy(ProductSupplierDestroyRequest $request, Product $product, Supplier $supplier, SupplierLinkAction $links)
    {
        $links->unlink($product, $supplier);

        return redirect()->back()->with('success', 'Leverancier ontkoppeld.');
    }
}
