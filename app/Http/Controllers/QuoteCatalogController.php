<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuoteCatalogSearchRequest;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductMaterialDefault;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * What can be offered, searched as the person types: active products and
 * materials in one list, with their sale price, or only one of the two. A product carries its standard
 * materials, so the screen can ask about them the moment it is picked.
 */
class QuoteCatalogController extends Controller
{
    private const LIMIT = 15;

    public function __invoke(QuoteCatalogSearchRequest $request, Quote $quote): JsonResponse
    {
        $like = '%' . trim((string) $request->validated('q', '')) . '%';
        $only = $request->validated('only');

        $products = $only === 'materials' ? collect() : Product::query()
            ->where('active', true)
            ->where(fn (Builder $query) => $query
                ->where('model', 'like', $like)
                ->orWhere('part_no', 'like', $like)
                ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', $like)))
            ->with(['brand', 'materialDefaults.material.usageUnit'])
            ->orderBy('model')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Product $product) => [
                'key' => 'product-' . $product->id,
                'type' => 'product',
                'id' => $product->id,
                'name' => $product->display_name,
                'price' => (float) ($product->retail_price ?? 0),
                'defaults' => $product->materialDefaults->map(fn (ProductMaterialDefault $default) => $default->toOption()),
            ]);

        $materials = $only === 'products' ? collect() : Material::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('code', 'like', $like))
            ->with('usageUnit')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Material $material) => [
                'key' => 'material-' . $material->id,
                'type' => 'material',
                'id' => $material->id,
                'name' => $material->name,
                'unit' => $material->usageUnit?->name,
                'price' => (float) ($material->price ?? 0),
            ]);

        return response()->json([...$products, ...$materials]);
    }
}
