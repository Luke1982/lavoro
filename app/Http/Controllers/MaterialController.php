<?php

namespace App\Http\Controllers;

use App\Http\Requests\MaterialReadRequest;
use App\Http\Requests\MaterialStoreRequest;
use App\Http\Requests\MaterialUpdateRequest;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialUsageUnit;
use App\Models\Supplier;

class MaterialController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(MaterialReadRequest $request)
    {
        $search = trim((string) $request->input('search', ''));

        $query = Material::with(['category', 'usageUnit']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhereHas('category', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('usageUnit', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        return inertia('Materials/IndexPage', [
            'materials' => $query->orderBy('name')->paginate(20)->appends(['search' => $search]),
            'categories' => MaterialCategory::orderBy('name')->get(),
            'usageUnits' => MaterialUsageUnit::orderBy('name')->get(),
            'search' => $search,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(MaterialStoreRequest $request)
    {
        $material = Material::create(array_merge([
            'description' => null,
            'code' => null,
            'vendor_code' => null,
            'price' => 0,
            'cost_price' => null,
            'divisable' => false,
            'is_active' => true,
            'is_service' => false,
            'stock' => 0,
            'min_stock' => 0,
            'max_stock' => 0,
        ], $request->validated()));

        if ($request->wantsJson()) {
            return response()->json($material->load(['category', 'usageUnit']));
        }

        return redirect()->route('materials.index')
            ->with('success', 'Materiaal aangemaakt.')
            ->with('extra', $material);
    }

    /**
     * Display the specified resource.
     */
    public function show(MaterialReadRequest $request, Material $material)
    {
        $material->load([
            'category',
            'usageUnit',
            'suppliers',
            'activities' => fn($q) => $q->with('user')->latest(),
        ]);

        $supplier_count = Supplier::count();
        $all_suppliers = $supplier_count <= 50
            ? Supplier::orderBy('name')->get(['id', 'name'])
            : collect();

        return inertia('Materials/ShowPage', [
            'material' => $material,
            'categories' => MaterialCategory::orderBy('name')->get(['id', 'name']),
            'usageUnits' => MaterialUsageUnit::orderBy('name')->get(['id', 'name']),
            'materialSuppliers' => $material->suppliers->map(fn($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'article_number' => $s->pivot->article_number,
                'is_preferred' => (bool) $s->pivot->is_preferred,
            ])->values()->all(),
            'allSuppliers' => $all_suppliers,
            'suppliersUseAjax' => $supplier_count > 50,
            'activities' => $material->activities,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(MaterialUpdateRequest $request, Material $material)
    {
        $material->update($request->validated());

        return redirect()->back()->with('success', 'Materiaal is bijgewerkt.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Material $material)
    {
        $material->delete();

        return redirect()->back();
    }
}
