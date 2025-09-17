<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\Request;
use App\Http\Requests\BrandReadRequest;

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
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $brand = Brand::create([
            'name' => $request->name,
        ]);

        return redirect()->route('brands.index')->with('success', 'Merk aangemaakt.')->with('extra', $brand);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Brand $brand)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $brand->update([
            'name' => $request->name,
        ]);

        return redirect()->route('brands.index')->with('success', 'Merk bijgewerkt.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Brand $brand)
    {
        $brand->delete();

        return redirect()->route('brands.index')->with('success', 'Merk verwijderd.');
    }
}
