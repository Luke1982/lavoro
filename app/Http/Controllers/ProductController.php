<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductRelation;
use App\Models\ProductType;
use App\Models\Customer;
use App\Http\Requests\ProductReadRequest;
use App\Http\Requests\ProductStoreUpdateRequest;
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

        $onlyType = $request->input('onlyType');
        if ($onlyType !== null && $onlyType !== '') {
            $products->where('product_type_id', $onlyType);
        }

        return inertia(
            'Products/IndexPage',
            [
                'products'     => $products
                    ->with(['brand', 'productType'])
                    ->orderBy('model')
                    ->paginate(20),
                'search'       => $search,
                'brands'       => Brand::all(),
                'productTypes' => ProductType::flatListWithPath(),
                'onlyType'     => $onlyType,
            ]
        );
    }

    /**
     * Build a query filtering by the given search terms.
     */
    private static function getByTerm($term)
    {
        $query = Product::with(['brand', 'productType']);

        $words = preg_split('/\s+/', trim($term));

        foreach ($words as $word) {
            $query->where(function ($q) use ($word) {
                $q->where('model', 'like', '%' . $word . '%')
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
            'productType.children',
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
        ]);

        $childTypeIds = $product->productType
            ? $product->productType->children()->pluck('id')->all()
            : [];

        $eligibleChildProducts = [];
        if (!empty($childTypeIds)) {
            $eligibleChildProducts = Product::query()
                ->whereIn('product_type_id', $childTypeIds)
                ->with(['brand', 'productType'])
                ->orderBy('model')
                ->get()
                ->map(fn($p) => [
                    'id'   => $p->id,
                    'name' => $p->brand->name . ' ' . $p->model . ' (' . $p->productType->name . ')',
                ])
                ->values()
                ->all();
        }

        $childProductsWithPivot = $product->childProducts->map(function ($child) {
            $pivot = $child->pivot;
            return [
                'productable_id'      => $pivot->id,
                'product_id'          => $child->id,
                'name'                => $child->brand->name . ' ' . $child->model
                    . ' (' . $child->productType->name . ')',
                'product_relation_id' => $pivot->product_relation_id,
                'quantity'            => $pivot->quantity,
                'is_required'         => $pivot->is_required,
            ];
        })->values()->all();

        return inertia('Products/ShowPage', [
            'product'               => $product,
            'allCustomers'          => Customer::orderBy('name', 'ASC')
                ->get(['id', 'name'])
                ->map(fn($c) => ['id' => $c->id, 'name' => $c->name]),
            'customFields'          => $product->allCustomFieldsWithValues(),
            'productRelations'      => ProductRelation::orderBy('name')->get(['id', 'name']),
            'eligibleChildProducts' => $eligibleChildProducts,
            'childProducts'         => $childProductsWithPivot,
            'requiredProductablesByProduct' => ProductableService::requiredProductablesMap(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ProductStoreUpdateRequest $request)
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
    public function update(ProductStoreUpdateRequest $request, Product $product)
    {
        $product->update($request->validated());

        return redirect()
            ->route($request->origin === 'showPage' ? 'products.show' : 'products.index', $product->id)
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
