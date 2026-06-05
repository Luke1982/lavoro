<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\Supplier;
use Illuminate\Http\Request;

class MaterialSupplierController extends Controller
{
    public function store(Request $request, Material $material)
    {
        abort_unless(
            $request->user()->isAdmin() || $request->user()->hasPermission('material.update'),
            403
        );

        $data = $request->validate([
            'supplier_id'    => ['required', 'exists:suppliers,id'],
            'article_number' => ['nullable', 'string', 'max:255'],
            'is_preferred'   => ['sometimes', 'boolean'],
        ]);

        $material->suppliers()->syncWithoutDetaching([
            $data['supplier_id'] => [
                'article_number' => $data['article_number'] ?? null,
                'is_preferred'   => $data['is_preferred'] ?? false,
            ],
        ]);

        return redirect()->back()->with('success', 'Leverancier gekoppeld.');
    }

    public function update(Request $request, Material $material, Supplier $supplier)
    {
        abort_unless(
            $request->user()->isAdmin() || $request->user()->hasPermission('material.update'),
            403
        );

        $data = $request->validate([
            'article_number' => ['nullable', 'string', 'max:255'],
            'is_preferred'   => ['sometimes', 'boolean'],
        ]);

        $material->suppliers()->updateExistingPivot($supplier->id, $data);

        return redirect()->back()->with('success', 'Leverancier bijgewerkt.');
    }

    public function destroy(Request $request, Material $material, Supplier $supplier)
    {
        abort_unless(
            $request->user()->isAdmin() || $request->user()->hasPermission('material.update'),
            403
        );

        $material->suppliers()->detach($supplier->id);

        return redirect()->back()->with('success', 'Leverancier ontkoppeld.');
    }
}
