<?php

namespace App\Http\Controllers;

use App\Http\Requests\MaterialUpdateRequest;
use App\Models\Material;
use App\Models\MaterialCategory;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return inertia('Materials/IndexPage', [
            'materials' => Material::with(['category', 'usageUnit'])->get(),
            'categories' => MaterialCategory::all(),
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
        //
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
    public function destroy(string $id)
    {
        //
    }
}
