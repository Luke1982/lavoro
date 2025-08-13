<?php

namespace App\Http\Controllers;

use App\Models\ProductType;
use Illuminate\Http\Request;
use App\Http\Requests\ProductTypeStoreUpdateRequest;

class ProductTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $types = ProductType::query();

        if ($request->has('search')) {
            $types = self::getByTerm($request->search);
        }

        return inertia(
            'ProductTypes/IndexPage',
            [
                'productTypes' => $types->orderBy('name')->paginate(20),
                'search'       => $request->search,
            ]
        );
    }

    /**
     * Build a query filtering by the given search terms.
     */
    private static function getByTerm(?string $term)
    {
        $query = ProductType::query();

        if ($term) {
            $words = explode(' ', $term);
            foreach ($words as $word) {
                $query->where(function ($q) use ($word) {
                    $q->where('name', 'like', '%' . strtolower($word) . '%');
                });
            }
        }

        return $query;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ProductTypeStoreUpdateRequest $request)
    {
        $type = ProductType::create($request->validated());

        return redirect()
            ->route('producttypes.index')
            ->with('success', 'Producttype aangemaakt.')
            ->with('extra', $type);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ProductTypeStoreUpdateRequest $request, ProductType $producttype)
    {
        $producttype->update($request->validated());

        return redirect()
            ->route('producttypes.index')
            ->with('success', 'Producttype bijgewerkt.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProductType $productType)
    {
        $productType->delete();

        return redirect()
            ->route('producttypes.index')
            ->with('success', 'Producttype verwijderd.');
    }
}
