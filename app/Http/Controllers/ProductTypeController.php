<?php

namespace App\Http\Controllers;

use App\Models\ProductType;
use App\Http\Requests\ProductTypeReadRequest;
use App\Http\Requests\ProductTypeStoreUpdateRequest;

class ProductTypeController extends Controller
{
    public function index(ProductTypeReadRequest $request)
    {
        $data   = $request->validated();
        $search = $data['search'] ?? null;

        if ($search) {
            $types = self::getByTerm($search)
                ->with(['products' => fn($q) => $q
                    ->select('id', 'product_type_id', 'model', 'brand_id')->with('brand:id,name')])
                ->orderBy('name')
                ->get();

            return inertia('ProductTypes/IndexPage', [
                'productTypes' => $types,
                'treeMode'     => false,
                'search'       => $search,
            ]);
        }

        $types = ProductType::whereNull('parent_id')
            ->with([
                'children.children.products' => fn($q) => $q
                    ->select('id', 'product_type_id', 'model', 'brand_id')->with('brand:id,name'),
                'children.products'          => fn($q) => $q
                    ->select('id', 'product_type_id', 'model', 'brand_id')->with('brand:id,name'),
                'products'                   => fn($q) => $q
                    ->select('id', 'product_type_id', 'model', 'brand_id')->with('brand:id,name'),
            ])
            ->orderBy('name')
            ->get();

        return inertia('ProductTypes/IndexPage', [
            'productTypes' => $types,
            'treeMode'     => true,
            'search'       => null,
        ]);
    }

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

    public function store(ProductTypeStoreUpdateRequest $request)
    {
        $type = ProductType::create($request->validated());

        return redirect()
            ->route('producttypes.index')
            ->with('success', 'Producttype aangemaakt.')
            ->with('extra', $type);
    }

    public function update(ProductTypeStoreUpdateRequest $request, ProductType $producttype)
    {
        $producttype->update($request->validated());

        return redirect()
            ->route('producttypes.index')
            ->with('success', 'Producttype bijgewerkt.');
    }

    public function destroy(ProductType $producttype)
    {
        if ($this->hasProducts($producttype)) {
            return back()->with(
                'error',
                'Dit producttype of een van de subtypes heeft nog gekoppelde producten en kan niet verwijderd worden.'
            );
        }

        $this->deleteRecursive($producttype);

        return redirect()
            ->route('producttypes.index')
            ->with('success', 'Producttype verwijderd.');
    }

    private function hasProducts(ProductType $type): bool
    {
        if ($type->products()->exists()) {
            return true;
        }

        foreach ($type->children as $child) {
            if ($this->hasProducts($child)) {
                return true;
            }
        }

        return false;
    }

    private function deleteRecursive(ProductType $type): void
    {
        foreach ($type->children as $child) {
            $this->deleteRecursive($child);
        }

        $type->delete();
    }
}
