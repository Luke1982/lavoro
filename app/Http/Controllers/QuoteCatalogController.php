<?php

namespace App\Http\Controllers;

use App\Domain\Quotes\CatalogPhoto;
use App\Http\Requests\QuoteCatalogSearchRequest;
use App\Models\Material;
use App\Models\Product;
use App\Models\Quote;
use App\Support\RichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * What can be offered, searched as the person types: active products and
 * materials in one list, with their sale price, or only one of the two. A product carries its standard
 * materials, so the screen can ask about them the moment it is picked. Each
 * comes with its photo and what tells two alike apart: a product's type and
 * article number, a material's code, unit and, for whoever may read
 * materials, its stock, and the start of either's description.
 */
class QuoteCatalogController extends Controller
{
    private const LIMIT = 15;

    private const DESCRIPTION_LENGTH = 120;

    public function __invoke(QuoteCatalogSearchRequest $request, Quote $quote): JsonResponse
    {
        $like = '%' . trim((string) $request->validated('q', '')) . '%';
        $only = $request->validated('only');
        $sees_stock = $request->user()->can('viewAny', Material::class);

        $products = $only === 'materials' ? collect() : Product::query()
            ->where('active', true)
            ->where(fn (Builder $query) => $query
                ->where('model', 'like', $like)
                ->orWhere('part_no', 'like', $like)
                ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', $like)))
            ->with(['brand', 'productType', 'materialDefaults.material.usageUnit', ...CatalogPhoto::RELATIONS[Product::class]])
            ->orderBy('model')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Product $product) => [
                'key' => 'product-' . $product->id,
                'type' => 'product',
                'id' => $product->id,
                'name' => $product->display_name,
                'price' => (float) ($product->retail_price ?? 0),
                'defaults' => $product->materialDefaults->map->toMaterialDefaultOption(),
                'image' => CatalogPhoto::of($product),
                'type_name' => $product->productType?->name,
                'part_no' => $product->part_no,
                'description' => self::summary($product->description),
            ]);

        $materials = $only === 'products' ? collect() : Material::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('code', 'like', $like))
            ->with(['usageUnit', ...CatalogPhoto::RELATIONS[Material::class]])
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
                'image' => CatalogPhoto::of($material),
                'code' => $material->code,
                'stock' => $sees_stock ? (float) $material->stock : null,
                'description' => self::summary($material->description),
            ]);

        return response()->json([...$products, ...$materials]);
    }

    private static function summary(?string $html): ?string
    {
        $plain = RichText::plain($html);

        return $plain === null ? null : Str::limit(Str::squish($plain), self::DESCRIPTION_LENGTH);
    }
}
