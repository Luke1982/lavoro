<?php

namespace App\Http\Controllers;

use App\Models\ProductAttribute;
use App\Models\ProductType;
use App\Http\Requests\ProductAttributeStoreRequest;
use App\Http\Requests\ProductAttributeUpdateRequest;
use App\Http\Requests\ProductAttributeDestroyRequest;
use Illuminate\Http\Request;

class ProductAttributeController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search', ''));
        $query  = ProductAttribute::with(['productTypes']);

        if ($search !== '') {
            $query->where('name', 'like', '%' . $search . '%');
        }

        return inertia('ProductAttributes/IndexPage', [
            'attributes' => $query->orderBy('name')->paginate(20),
            'search'     => $search,
        ]);
    }

    public function show(Request $request, ProductAttribute $productattribute)
    {
        if (! $request->user()->hasPermission('productattribute.read')) {
            abort(403);
        }

        $productattribute->load(['values' => fn($q) => $q->orderBy('value'), 'productTypes']);

        return inertia('ProductAttributes/ShowPage', [
            'attribute'    => $productattribute,
            'productTypes' => ProductType::flatListWithPath(),
        ]);
    }

    public function store(ProductAttributeStoreRequest $request)
    {
        $attribute = ProductAttribute::create($request->validated());

        return redirect()->route('productattributes.show', $attribute)
            ->with('success', 'Kenmerk aangemaakt.');
    }

    public function update(ProductAttributeUpdateRequest $request, ProductAttribute $productattribute)
    {
        $productattribute->update($request->validated());

        return redirect()->back()->with('success', 'Kenmerk bijgewerkt.');
    }

    public function destroy(ProductAttributeDestroyRequest $request, ProductAttribute $productattribute)
    {
        $productattribute->delete();

        return redirect()->route('productattributes.index')
            ->with('success', 'Kenmerk verwijderd.');
    }
}
