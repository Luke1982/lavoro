<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductBulkUpdateAttributesRequest;
use App\Http\Requests\ProductReadRequest;
use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Supplier;
use App\Models\ProductAttributeValue;
use App\Models\ProductRelation;
use App\Models\ProductType;
use App\Services\ProductableService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $products = Product::query();

        $search = trim((string) $request->input('search', ''));
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

        $onlyAttributeValues = array_values(array_filter(explode(',', $request->input('onlyAttributeValues', '')), fn($v) => $v !== ''));
        if (count($onlyAttributeValues)) {
            $values_by_attr = [];
            foreach (ProductAttributeValue::whereIn('id', $onlyAttributeValues)->get(['id', 'product_attribute_id']) as $av) {
                $values_by_attr[$av->product_attribute_id][] = (int) $av->id;
            }
            foreach ($values_by_attr as $val_ids) {
                $products->whereHas('productAttributeValueables', fn($q) => $q->whereIn('product_attribute_value_id', $val_ids));
            }
        }

        return inertia(
            'Products/IndexPage',
            [
                'products' => $products
                    ->with([
                        'brand',
                        'productType',
                        'mainImage',
                        'productAttributeValueables.productAttribute',
                        'productAttributeValueables.value',
                    ])
                    ->orderBy('model')
                    ->paginate(max(1, min(100, (int) $request->input('perPage', 20))))
                    ->through(function ($p) {
                        $p->setAttribute('attribute_value_map', $p->attributeValueMap());

                        return $p;
                    }),
                'search' => $search,
                'brands' => Brand::all(),
                'productTypes' => ProductType::flatListWithPath(),
                'productAttributes' => ProductAttribute::with(['values', 'productTypes'])
                    ->get()
                    ->filter(fn($attr) => $attr->values->isNotEmpty())
                    ->map(fn($attr) => [
                        'id'               => $attr->id,
                        'name'             => $attr->name,
                        'values'           => $attr->values
                            ->map(fn($v) => ['id' => $v->id, 'value' => $v->value])
                            ->values(),
                        'product_type_ids' => $attr->productTypes->pluck('id')->values(),
                    ])
                    ->values(),
                'onlyType' => $onlyType,
                'onlyBrand' => $onlyBrand,
                'onlyAttributeValues' => $onlyAttributeValues,
                'perPage' => max(1, min(100, (int) $request->input('perPage', 20))),
            ]
        );
    }

    /**
     * Build a query filtering by the given search terms.
     */
    private static function getByTerm($term)
    {
        $query = Product::with([
            'brand',
            'productType',
            'mainImage',
            'productAttributeValueables.productAttribute',
            'productAttributeValueables.value',
        ]);

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
            'mainImage',
            'documents',
            'assets.customer',
            'assets.openTickets',
            'assets.pendingTickets',
            'assets.closedTickets',
            'assets.product.productType',
            'assets.product.brand',
            'customFields',
            'suppliers',
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
                'id' => $p->id,
                'name' => $p->brand->name . ' ' . $p->model
                    . ' (' . ($typePaths[$p->product_type_id] ?? $p->productType->name) . ')',
            ])
            ->values()
            ->all();

        $childProductsWithPivot = $product->childProducts->map(function ($child) use ($typePaths) {
            $pivot = $child->pivot;

            return [
                'productable_id' => $pivot->id,
                'product_id' => $child->id,
                'name' => $child->brand->name . ' ' . $child->model
                    . ' (' . ($typePaths[$child->product_type_id] ?? $child->productType->name) . ')',
                'product_relation_id' => $pivot->product_relation_id,
                'quantity' => $pivot->quantity,
                'is_required' => $pivot->is_required,
            ];
        })->values()->all();

        $parentProductsWithPivot = $product->parentProducts->map(function ($parent) use ($typePaths) {
            $pivot = $parent->pivot;

            return [
                'productable_id' => $pivot->id,
                'product_id' => $parent->id,
                'name' => $parent->brand->name . ' ' . $parent->model
                    . ' (' . ($typePaths[$parent->product_type_id] ?? $parent->productType->name) . ')',
                'product_relation_id' => $pivot->product_relation_id,
                'quantity' => $pivot->quantity,
                'is_required' => $pivot->is_required,
            ];
        })->values()->all();

        $customer_count = Customer::count();

        $supplier_count = Supplier::count();
        $all_suppliers  = $supplier_count <= 50
            ? Supplier::orderBy('name')->get(['id', 'name'])
            : collect();

        return inertia('Products/ShowPage', [
            'product' => $product,
            'productTypes' => ProductType::flatListWithPath(),
            'allCustomers' => $customer_count <= 50
                ? Customer::orderBy('name')->get(['id', 'name'])
                : collect(),
            'customersUseAjax' => $customer_count > 50,
            'customFields' => $product->allCustomFieldsWithValues(),
            'productRelations' => ProductRelation::orderBy('name')->get(['id', 'name']),
            'eligibleChildProducts' => $eligibleChildProducts,
            'childProducts' => $childProductsWithPivot,
            'parentProducts' => $parentProductsWithPivot,
            'requiredProductablesByProduct' => ProductableService::requiredProductablesMap(),
            'productAttributes' => $product->productType->productAttributes()
                ->with('values')->orderBy('name')->get(),
            'selectedAttributeValues' => $product->productAttributeValueables()
                ->pluck('product_attribute_value_id', 'product_attribute_id'),
            'productSuppliers'    => $product->suppliers->map(fn($s) => [
                'id'             => $s->id,
                'name'           => $s->name,
                'article_number' => $s->pivot->article_number,
                'is_preferred'   => (bool) $s->pivot->is_preferred,
            ])->values()->all(),
            'allSuppliers'        => $all_suppliers,
            'suppliersUseAjax'    => $supplier_count > 50,
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

    public function bulkUpdateAttributes(ProductBulkUpdateAttributesRequest $request)
    {
        DB::transaction(function () use ($request) {
            $products = Product::whereIn('id', $request->product_ids)
                ->with('productType.productAttributes')
                ->get();

            foreach ($request->attributes as $attr) {
                $attributeId = $attr['product_attribute_id'];
                $valueId     = $attr['product_attribute_value_id'];

                foreach ($products as $product) {
                    if (! $product->productType->productAttributes->contains('id', $attributeId)) {
                        continue;
                    }

                    $product->productAttributeValueables()
                        ->where('product_attribute_id', $attributeId)
                        ->delete();

                    $product->productAttributeValueables()->create([
                        'product_attribute_id'        => $attributeId,
                        'product_attribute_value_id'  => $valueId,
                    ]);
                }
            }
        });

        return redirect()->back()->with('success', 'Kenmerken bijgewerkt.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()
            ->back()
            ->with('success', 'Product verwijderd.');
    }
}
