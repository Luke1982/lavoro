<?php

namespace App\Http\Controllers;

use App\Actions\Suppliers\SupplierLinkAction;
use App\Http\Requests\MaterialSupplierDestroyRequest;
use App\Http\Requests\MaterialSupplierStoreRequest;
use App\Http\Requests\MaterialSupplierUpdateRequest;
use App\Models\Material;
use App\Models\Supplier;

class MaterialSupplierController extends Controller
{
    public function store(MaterialSupplierStoreRequest $request, Material $material, SupplierLinkAction $links)
    {
        $data = $request->validated();

        $links->link($material, Supplier::findOrFail($data['supplier_id']), [
            'article_number' => $data['article_number'] ?? null,
            'is_preferred' => $data['is_preferred'] ?? false,
        ]);

        return redirect()->back()->with('success', 'Leverancier gekoppeld.');
    }

    public function update(MaterialSupplierUpdateRequest $request, Material $material, Supplier $supplier, SupplierLinkAction $links)
    {
        $links->change($material, $supplier, $request->validated());

        return redirect()->back()->with('success', 'Leverancier bijgewerkt.');
    }

    public function destroy(MaterialSupplierDestroyRequest $request, Material $material, Supplier $supplier, SupplierLinkAction $links)
    {
        $links->unlink($material, $supplier);

        return redirect()->back()->with('success', 'Leverancier ontkoppeld.');
    }
}
