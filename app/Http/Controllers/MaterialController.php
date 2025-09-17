<?php

namespace App\Http\Controllers;

use App\Http\Requests\MaterialUpdateRequest;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialUsageUnit;
use Illuminate\Http\Request;
use App\Http\Requests\MaterialReadRequest;

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
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'material_category_id' => 'required|exists:material_categories,id',
            'code' => 'nullable|string|max:255',
            'vendor_code' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'material_usage_unit_id' => 'required|exists:material_usage_units,id',
            'divisable' => 'boolean',
            'is_active' => 'boolean',
            'is_service' => 'boolean',
            'stock' => 'nullable|numeric|min:0',
            'min_stock' => 'nullable|numeric|min:0',
            'max_stock' => 'nullable|numeric|min:0',
        ]);

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
        ], $data));

        return redirect()->route('materials.index')
            ->with('success', 'Materiaal aangemaakt.')
            ->with('extra', $material);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
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

        return redirect()->route('materials.index')->with('success', 'Materiaal is bijgewerkt.');
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
