<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductRelation;
use App\Models\ProductType;
use App\Models\Customer;
use App\Http\Requests\ProductReadRequest;
use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Services\ProductableService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $products = Product::query();

        $search = trim((string)$request->input('search', ''));
        if ($search !== '') {
            $products = self::getByTerm($search);
        }

        $onlyType = array_values(array_filter(explode(',', $request->input('onlyType', '')), fn($v) => $v !== ''));
        if (count($onlyType)) {
            $products->whereIn('product_type_id', $onlyType);
        }

        $onlyBrand = array_values(array_filter(explode(',', $request->input('onlyBrand', '')), fn($v) => $v !== ''));
        if (count($onlyBrand)) {
            $products->whereIn('brand_id', $onlyBrand);
        }

        return inertia(
            'Products/IndexPage',
            [
                'products'     => $products
                    ->with(['brand', 'productType', 'mainImage'])
                    ->orderBy('model')
                    ->paginate(max(1, min(100, (int)$request->input('perPage', 20)))),
                'search'       => $search,
                'brands'       => Brand::all(),
                'productTypes' => ProductType::flatListWithPath(),
                'onlyType'     => $onlyType,
                'onlyBrand'    => $onlyBrand,
                'perPage'      => max(1, min(100, (int)$request->input('perPage', 20))),
            ]
        );
    }

    /**
     * Build a query filtering by the given search terms.
     */
    private static function getByTerm($term)
    {
        $query = Product::with(['brand', 'productType', 'mainImage']);

        $words = preg_split('/\s+/', trim($term));

        foreach ($words as $word) {
            $query->where(function ($q) use ($word) {
                $q->where('model', 'like', '%' . $word . '%')
                    ->orWhere('part_no', 'like', '%' . $word . '%')
                    ->orWhereHas('brand', function ($qb) use ($word) {
                        $qb->where('name', 'like', '%' . $word . '%');
                    })
                    ->orWhereHas('productType', function ($qb) use ($word) {
                        $qb->where('name', 'like', '%' . $word . '%');
                    });
            });
        }

        return $query;
    }

    public function show(ProductReadRequest $request, Product $product)
    {
        $product->load([
            'brand',
            'productType',
            'images',
            'documents',
            'assets.customer',
            'assets.openTickets',
            'assets.pendingTickets',
            'assets.closedTickets',
            'assets.product.productType',
            'assets.product.brand',
            'customFields',
            'childProducts.brand',
            'childProducts.productType',
            'parentProducts.brand',
            'parentProducts.productType',
        ]);

        $typePaths = ProductType::pathMap();

        $eligibleChildProducts = Product::query()
            ->where('id', '!=', $product->id)
            ->where('bundle', false)
            ->with(['brand', 'productType'])
            ->orderBy('model')
            ->get()
            ->map(fn($p) => [
                'id'   => $p->id,
                'name' => $p->brand->name . ' ' . $p->model
                    . ' (' . ($typePaths[$p->product_type_id] ?? $p->productType->name) . ')',
            ])
            ->values()
            ->all();

        $childProductsWithPivot = $product->childProducts->map(function ($child) use ($typePaths) {
            $pivot = $child->pivot;
            return [
                'productable_id'      => $pivot->id,
                'product_id'          => $child->id,
                'name'                => $child->brand->name . ' ' . $child->model
                    . ' (' . ($typePaths[$child->product_type_id] ?? $child->productType->name) . ')',
                'product_relation_id' => $pivot->product_relation_id,
                'quantity'            => $pivot->quantity,
                'is_required'         => $pivot->is_required,
            ];
        })->values()->all();

        $parentProductsWithPivot = $product->parentProducts->map(function ($parent) use ($typePaths) {
            $pivot = $parent->pivot;
            return [
                'productable_id'      => $pivot->id,
                'product_id'          => $parent->id,
                'name'                => $parent->brand->name . ' ' . $parent->model
                    . ' (' . ($typePaths[$parent->product_type_id] ?? $parent->productType->name) . ')',
                'product_relation_id' => $pivot->product_relation_id,
                'quantity'            => $pivot->quantity,
                'is_required'         => $pivot->is_required,
            ];
        })->values()->all();

        return inertia('Products/ShowPage', [
            'product'               => $product,
            'productTypes'          => ProductType::flatListWithPath(),
            'allCustomers'          => Customer::orderBy('name', 'ASC')
                ->get(['id', 'name'])
                ->map(fn($c) => ['id' => $c->id, 'name' => $c->name]),
            'customFields'          => $product->allCustomFieldsWithValues(),
            'productRelations'      => ProductRelation::orderBy('name')->get(['id', 'name']),
            'eligibleChildProducts' => $eligibleChildProducts,
            'childProducts'         => $childProductsWithPivot,
            'parentProducts'        => $parentProductsWithPivot,
            'requiredProductablesByProduct' => ProductableService::requiredProductablesMap(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ProductStoreRequest $request)
    {
        $product = Product::create($request->validated());

        return redirect()
            ->route('products.index')
            ->with('success', 'Product aangemaakt.')
            ->with('extra', $product->load(['brand', 'productType']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ProductUpdateRequest $request, Product $product)
    {
        $product->update($request->validated());

        return redirect()
            ->back()
            ->with('success', 'Product bijgewerkt.')
            ->with('extra', $product->load(['brand', 'productType']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()
            ->route('products.index')
            ->with('success', 'Product verwijderd.');
    }
}
