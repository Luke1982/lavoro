<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Http\Requests\SupplierReadRequest;
use App\Http\Requests\SupplierStoreRequest;
use App\Http\Requests\SupplierUpdateRequest;
use App\Http\Requests\SupplierDestroyRequest;

class SupplierController extends Controller
{
    public function index(SupplierReadRequest $request)
    {
        $search = $request->input('search');

        $suppliers = Supplier::when($search !== null && $search !== '', fn ($query) =>
            $query->where(fn ($q) =>
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%")
                  ->orWhere('kvk_number', 'like', "%{$search}%")
                  ->orWhere('vat_number', 'like', "%{$search}%")
            ))
            ->orderBy('name')
            ->paginate(25)
            ->appends(['search' => $search]);

        return inertia('Suppliers/IndexPage', [
            'suppliers' => $suppliers,
        ]);
    }

    public function show(SupplierReadRequest $request, Supplier $supplier)
    {
        $supplier->load([
            'products.brand',
            'products.productType',
            'materials',
        ]);

        return inertia('Suppliers/ShowPage', [
            'supplier' => $supplier,
        ]);
    }

    public function store(SupplierStoreRequest $request)
    {
        Supplier::create($request->sanitized());

        return redirect()->route('suppliers.index')->with('success', 'Leverancier aangemaakt.');
    }

    public function update(SupplierUpdateRequest $request, Supplier $supplier)
    {
        $supplier->update($request->sanitized());

        return redirect()->back()->with('success', 'Leverancier bijgewerkt.');
    }

    public function destroy(SupplierDestroyRequest $request, Supplier $supplier)
    {
        $supplier->delete();

        return redirect()->route('suppliers.index')->with('success', 'Leverancier verwijderd.');
    }
}
