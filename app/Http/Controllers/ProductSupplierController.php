<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\Request;

class ProductSupplierController extends Controller
{
    public function store(Request $request, Product $product)
    {
        abort_unless(
            $request->user()->isAdmin() || $request->user()->hasPermission('product.update'),
            403
        );

        $data = $request->validate([
            'supplier_id'    => ['required', 'exists:suppliers,id'],
            'article_number' => ['nullable', 'string', 'max:255'],
            'is_preferred'   => ['sometimes', 'boolean'],
        ]);

        $product->suppliers()->syncWithoutDetaching([
            $data['supplier_id'] => [
                'article_number' => $data['article_number'] ?? null,
                'is_preferred'   => $data['is_preferred'] ?? false,
            ],
        ]);

        return redirect()->back()->with('success', 'Leverancier gekoppeld.');
    }

    public function update(Request $request, Product $product, Supplier $supplier)
    {
        abort_unless(
            $request->user()->isAdmin() || $request->user()->hasPermission('product.update'),
            403
        );

        $data = $request->validate([
            'article_number' => ['nullable', 'string', 'max:255'],
            'is_preferred'   => ['sometimes', 'boolean'],
        ]);

        $product->suppliers()->updateExistingPivot($supplier->id, $data);

        return redirect()->back()->with('success', 'Leverancier bijgewerkt.');
    }

    public function destroy(Request $request, Product $product, Supplier $supplier)
    {
        abort_unless(
            $request->user()->isAdmin() || $request->user()->hasPermission('product.update'),
            403
        );

        $product->suppliers()->detach($supplier->id);

        return redirect()->back()->with('success', 'Leverancier ontkoppeld.');
    }
}
