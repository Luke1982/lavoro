<?php

namespace App\Http\Controllers;

use App\Actions\Products\SetProductAttributeValuesAction;
use App\Enums\DefaultMaterialPrompts;
use App\Http\Requests\ProductBulkUpdateRequest;
use App\Http\Requests\ProductCreateRequest;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Requests\ProductReadRequest;
use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DocumentCategory;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductMaterialDefault;
use App\Models\ProductRelation;
use App\Models\ProductType;
use App\Models\Quote;
use App\Models\Supplier;
use App\Policies\QuotePolicy;
use App\Services\ProductableService;
use App\Traits\ReadsPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    use ReadsPerPage;

    public function index(ProductIndexRequest $request)
    {
        $search = trim((string) $request->validated('search'));
        $products = $search === '' ? Product::query() : self::getByTerm($search);

        $only_type = $request->idList('onlyType');
        $only_brand = $request->idList('onlyBrand');
        $only_attribute_values = $request->idList('onlyAttributeValues');
        $ranges = $request->ranges();

        $products
            ->when($only_type !== [], fn ($query) => $query->whereIn('product_type_id', $only_type))
            ->when($only_brand !== [], fn ($query) => $query->whereIn('brand_id', $only_brand))
            ->when($only_attribute_values !== [], fn ($query) => $query->withAttributeValues($only_attribute_values));

        foreach ($ranges as $attribute_id => ['min' => $min, 'max' => $max]) {
            $products->withAttributeBetween($attribute_id, $min, $max);
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
                    ->paginate($this->perPage($request))
                    ->through(function ($p) {
                        $p->setAttribute('attribute_value_map', $p->attributeValueMap());

                        return $p;
                    }),
                'search' => $search,
                ...$this->formOptions(),
                'onlyType' => $only_type,
                'onlyBrand' => $only_brand,
                'onlyAttributeValues' => $only_attribute_values,
                'ranges' => (object) $ranges,
                'perPage' => $this->perPage($request),
                'canImport' => $request->user()->can('import', Product::class),
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
            'documents.category',
            'documents.user:id,name',
            'assets.customer',
            'assets.linkedLocation',
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
            'activities' => fn ($query) => $query->visibleTo($request->user())
                ->with(['user:id,name', 'fieldChanges'])
                ->orderByDesc('activityables.created_at'),
        ]);

        $typePaths = ProductType::pathMap();

        $eligibleChildProducts = Product::query()
            ->where('id', '!=', $product->id)
            ->where('bundle', false)
            ->with(['brand', 'productType'])
            ->orderBy('model')
            ->get()
            ->map(fn ($p) => [
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
                'flex_quantity' => (bool) $pivot->flex_quantity,
                'is_required' => $pivot->is_required,
                'requires_serial' => $child->requiresSerial(),
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
                'flex_quantity' => (bool) $pivot->flex_quantity,
                'is_required' => $pivot->is_required,
            ];
        })->values()->all();

        $customer_count = Customer::count();

        $supplier_count = Supplier::count();
        $all_suppliers = $supplier_count <= 50
            ? Supplier::orderBy('name')->get(['id', 'name'])
            : collect();

        return inertia('Products/ShowPage', [
            'product' => $product,
            'machineOption' => $product->toMachineOption(),
            'documentCategories' => DocumentCategory::forPicker(),
            'productTypes' => ProductType::flatListWithPath(),
            'ledgerAccounts' => LedgerAccount::choices(),
            'allCustomers' => $customer_count <= 50
                ? Customer::orderBy('name')->get(['id', 'name'])
                : collect(),
            'customersUseAjax' => $customer_count > 50,
            'customFields' => $product->allCustomFieldsWithValues(),
            'productRelations' => ProductRelation::orderBy('name')->get(['id', 'name']),
            'eligibleChildProducts' => $eligibleChildProducts,
            'childProducts' => $childProductsWithPivot,
            'parentProducts' => $parentProductsWithPivot,
            'bundlePartsByProduct' => ProductableService::bundlePartsMap(),
            'productAttributes' => $product->productType->productAttributes()
                ->with('values')->orderBy('name')->get(),
            'selectedAttributeValues' => $product->productAttributeValueables()
                ->pluck('product_attribute_value_id', 'product_attribute_id'),
            'productSuppliers' => $product->suppliers->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'article_number' => $s->pivot->article_number,
                'is_preferred' => (bool) $s->pivot->is_preferred,
            ])->values()->all(),
            'allSuppliers' => $all_suppliers,
            'suppliersUseAjax' => $supplier_count > 50,
            'materialDefaults' => $this->materialDefaults($request, $product),
            'materialDefaultPrompts' => DefaultMaterialPrompts::comboBoxArray(),
        ]);
    }

    /**
     * The materials that come along when this product is offered on a quote.
     * Null rather than empty for whoever may not set them, so the page leaves
     * the chapter out instead of showing an empty one. The licence is asked
     * apart from the policy: a super admin passes every policy, also for a
     * customer without the module, and would get a chapter whose routes refuse.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function materialDefaults(ProductReadRequest $request, Product $product): ?array
    {
        if (!QuotePolicy::licensed() || !$request->user()->can('manageDefaults', Quote::class)) {
            return null;
        }

        return $product->materialDefaults()->with('material.usageUnit')->get()
            ->map(fn (ProductMaterialDefault $default) => $default->toOption())
            ->all();
    }

    /**
     * What a new product is made from, for a form that is not on the products page:
     * the machine form makes a missing product on the spot.
     */
    public function create(ProductCreateRequest $request): JsonResponse
    {
        return response()->json($this->formOptions());
    }

    /**
     * The brands, types and attributes a product form offers. An attribute without
     * values has nothing to pick, so it is left out.
     *
     * @return array{brands: Collection, productTypes: array, productAttributes: Collection}
     */
    private function formOptions(): array
    {
        return [
            'brands' => Brand::all(),
            'productTypes' => ProductType::flatListWithPath(),
            'productAttributes' => ProductAttribute::with(['values', 'productTypes'])
                ->get()
                ->filter(fn ($attr) => $attr->values->isNotEmpty())
                ->map(fn ($attr) => [
                    'id' => $attr->id,
                    'name' => $attr->name,
                    'value_type' => $attr->value_type,
                    'unit' => $attr->unit,
                    'values' => $attr->values
                        ->map(fn ($v) => ['id' => $v->id, 'value' => $v->value])
                        ->values(),
                    'product_type_ids' => $attr->productTypes->pluck('id')->values(),
                ])
                ->values(),
        ];
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ProductStoreRequest $request, SetProductAttributeValuesAction $set_values)
    {
        $product = DB::transaction(function () use ($request, $set_values) {
            $product = Product::create($request->safe()->except('attributes'));
            $set_values->handle($product, self::valuesByAttribute($request->validated('attributes', [])));

            return $product;
        });

        if ($request->wantsJson()) {
            return response()->json($product->refresh()->toMachineOption(), 201);
        }

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

    public function bulkUpdate(ProductBulkUpdateRequest $request, SetProductAttributeValuesAction $set_values)
    {
        DB::transaction(function () use ($request, $set_values) {
            $product_ids = $request->input('product_ids');

            if ($request->filled('product_type_id')) {
                Product::whereIn('id', $product_ids)->update(['product_type_id' => $request->input('product_type_id')]);
            }

            if ($request->filled('brand_id')) {
                Product::whereIn('id', $product_ids)->update(['brand_id' => $request->input('brand_id')]);
            }

            $values = self::valuesByAttribute($request->validated('attributes', []));

            if ($values !== []) {
                $set_values->handleMany(Product::whereIn('id', $product_ids)->get(), $values);
            }
        });

        return redirect()->back()->with('success', 'Producten bijgewerkt.');
    }

    /**
     * @param  array<int, array{product_attribute_id: int, product_attribute_value_id: ?int}>  $attributes
     * @return array<int, ?int> kenmerk id → value id
     */
    private static function valuesByAttribute(array $attributes): array
    {
        return array_column($attributes, 'product_attribute_value_id', 'product_attribute_id');
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
