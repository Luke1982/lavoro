<?php

namespace App\Http\Controllers;

use App\Models\ProductRelation;
use App\Http\Requests\ProductRelationStoreUpdateRequest;
use Illuminate\Http\Request;

class ProductRelationController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search', ''));
        $query  = ProductRelation::query();

        if ($search !== '') {
            $query->where('name', 'like', '%' . $search . '%');
        }

        return inertia('ProductRelations/IndexPage', [
            'relations' => $query->orderBy('name')->paginate(20),
            'search'    => $search,
        ]);
    }

    public function store(ProductRelationStoreUpdateRequest $request)
    {
        ProductRelation::create($request->validated());

        return redirect()->back()->with('success', 'Relatietype aangemaakt.');
    }

    public function update(ProductRelationStoreUpdateRequest $request, ProductRelation $productrelation)
    {
        $productrelation->update($request->validated());

        return redirect()->back()->with('success', 'Relatietype bijgewerkt.');
    }

    public function destroy(ProductRelation $productrelation)
    {
        $productrelation->delete();

        return redirect()->back()->with('success', 'Relatietype verwijderd.');
    }
}
