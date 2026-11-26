<?php

namespace App\Http\Controllers;

use App\Http\Requests\MaterialCategoryDestroyRequest;
use App\Http\Requests\MaterialCategoryReadRequest;
use App\Http\Requests\MaterialCategoryStoreUpdateRequest;
use App\Models\MaterialCategory;

class MaterialCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(MaterialCategoryReadRequest $request)
    {
        return inertia('Materials/CategoryIndexPage', [
            'categories' => MaterialCategory::all(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(MaterialCategoryStoreUpdateRequest $request)
    {
        $category = MaterialCategory::create($request->validated());

        return redirect()->back()
            ->with('success', 'Materiaal categorie is aangemaakt.')
            ->with('extra', $category);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(MaterialCategoryStoreUpdateRequest $request, MaterialCategory $materialcategory)
    {
        $materialcategory->update($request->validated());

        return redirect()->back()->with('success', 'Materiaal categorie is bijgewerkt.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MaterialCategoryDestroyRequest $request, MaterialCategory $materialcategory)
    {
        $materialcategory->delete();

        return redirect()->back();
    }
}
