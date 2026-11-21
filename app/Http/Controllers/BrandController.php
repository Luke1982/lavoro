<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Http\Requests\BrandDestroyRequest;
use App\Http\Requests\BrandReadRequest;
use App\Http\Requests\BrandStoreRequest;
use App\Http\Requests\BrandUpdateRequest;
use App\Models\Brand;

class BrandController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(BrandReadRequest $request)
    {
        $brands = Brand::query();
        $data = method_exists($request, 'validated') ? $request->validated() : [];
        $search = $data['search'] ?? null;
        if ($search) {
            $brands = self::getByTerm($search);
        }

        return inertia(
            'Brands/IndexPage',
            [
                'brands' => $brands->orderBy('name')->paginate(20),
                'search' => $search,
            ]
        );
    }

    private static function getByTerm(?string $term)
    {
        $query = Brand::query();

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
    public function store(BrandStoreRequest $request)
    {
        $brand = Brand::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json($brand, 201);
        }

        return redirect()->route('brands.index')->with('success', 'Merk aangemaakt.')->with('extra', $brand);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BrandUpdateRequest $request, Brand $brand)
    {
        $brand->update($request->validated());

        return redirect()->route('brands.index')->with('success', 'Merk bijgewerkt.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BrandDestroyRequest $request, Brand $brand)
    {
        $products = $brand->products()->count();

        if ($products > 0) {
            throw new Refusal(
                'Merk ' . $brand->name . ' kan niet verwijderd worden: '
                    . ($products === 1 ? 'er is nog 1 product' : 'er zijn nog ' . $products . ' producten')
                    . ' van dit merk. Verwijderen zou ook '
                    . ($products === 1 ? 'dat product en zijn machines' : 'die producten en hun machines')
                    . ' bij klanten weghalen.'
            );
        }

        $brand->delete();

        return redirect()->route('brands.index')->with('success', 'Merk verwijderd.');
    }
}
