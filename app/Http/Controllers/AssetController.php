<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssetChildStoreRequest;
use App\Http\Requests\AssetDestroyRequest;
use App\Http\Requests\AssetReadRequest;
use App\Http\Requests\AssetStoreRequest;
use App\Http\Requests\AssetUpdateRequest;
use App\Models\Asset;
use App\Models\AssetRelation;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Productable;
use App\Models\ProductRelation;
use App\Models\ProductType;
use App\Services\ProductableService;
use App\Traits\ReadsPerPage;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

// duplicate imports removed

class AssetController extends Controller
{
    use ReadsPerPage;

    /**
     * Display a listing of the resource.
     */
    /**
     * List assets with optional search filter.
     *
     * @return Response
     */
    public function index(AssetReadRequest $request)
    {

        $validated = $request->validated();
        $search = isset($validated['search']) ? (string) $validated['search'] : '';

        $query = Asset::with([
            'product.brand',
            'product.images',
            'product.productType',
            'customer',
        ])->withCount([
            'tickets as open_tickets_count' => fn($q) => $q->where('status', 'Open'),
            'tickets as pending_tickets_count' => fn($q) => $q->where('status', 'In behandeling'),
            'tickets as closed_tickets_count' => fn($q) => $q->where('status', 'Gesloten'),
        ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('serial_number', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($q2) use ($search) {
                        $q2->where('model', 'like', "%{$search}%");
                    })
                    ->orWhereHas('product.brand', function ($q3) use ($search) {
                        $q3->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('product.productType', function ($q4) use ($search) {
                        $q4->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('customer', function ($q5) use ($search) {
                        $q5->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $assets = $query
            ->orderBy('next_service_date', 'ASC')
            ->paginate($this->perPage($request))
            ->appends(['search' => $search]);

        $all_products = Product::with(['brand', 'productType'])
            ->join('product_types', 'products.product_type_id', '=', 'product_types.id')
            ->orderBy('product_types.name', 'ASC')
            ->select('products.*')
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->brand->name . ' ' . $product->model .
                        ' (' . $product->productType->name . ')',
                    'bundle' => $product->bundle,
                    'typical_certificate_days' => $product->typical_certificate_days,
                    'product_type_typical_certificate_days' => $product->productType->typical_certificate_days,
                ];
            });

        $customer_count = Customer::count();
        $product_count = Product::count();

        return inertia('Assets/IndexPage', [
            'assets' => $assets,
            'initialSearch' => $search,
            'allProducts' => $product_count <= 50 ? $all_products : collect(),
            'productsUseAjax' => $product_count > 50,
            'allCustomers' => $customer_count <= 50
                ? Customer::orderBy('name')->get(['id', 'name'])
                : collect(),
            'customersUseAjax' => $customer_count > 50,
            'requiredProductablesByProduct' => ProductableService::requiredProductablesMap(),
            'perPage' => $this->perPage($request),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AssetStoreRequest $request)
    {
        $validated = $request->validated();

        $asset = DB::transaction(function () use ($validated) {
            $asset = Asset::create([
                'product_id' => $validated['product_id'],
                'customer_id' => $validated['customer_id'],
                'serial_number' => $validated['serial_number'] ?? null,
                'next_service_date' => $validated['next_service_date'] ?? null,
                'date_in_service' => $validated['date_in_service'] ?? null,
                'status' => ($validated['is_active'] ?? true) ? 'Actief' : 'Niet actief',
            ]);

            foreach ($validated['child_assets'] ?? [] as $childData) {
                $productable = Productable::find($childData['productable_id']);
                if (! $productable || ! $productable->is_required) {
                    continue;
                }

                $childAsset = Asset::create([
                    'product_id' => $productable->productable_id,
                    'customer_id' => $validated['customer_id'],
                    'serial_number' => $childData['serial_number'],
                    'next_service_date' => $validated['next_service_date'] ?? null,
                    'status' => ($validated['is_active'] ?? true) ? 'Actief' : 'Niet actief',
                ]);

                AssetRelation::create([
                    'parent_asset_id' => $asset->id,
                    'child_asset_id' => $childAsset->id,
                    'productable_id' => $productable->id,
                    'product_relation_id' => $productable->product_relation_id,
                ]);
            }

            return $asset;
        });

        $created = $asset->load([
            'product.brand',
            'product.images',
            'product.productType',
            'customer',
        ]);

        return redirect()->back()
            ->with('success', 'Machine toegevoegd.')
            ->with('extra', $created);
    }

    /**
     * Show a single asset.
     *
     * @return Response
     */
    public function show(AssetReadRequest $request, Asset $asset)
    {
        $all_products = Product::with(['brand', 'productType'])
            ->join('product_types', 'products.product_type_id', '=', 'product_types.id')
            ->orderBy('product_types.name', 'ASC')
            ->select('products.*')
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->brand->name . ' ' .
                        $product->model . ' (' . $product->productType->name . ')',
                    'bundle' => $product->bundle,
                    'typical_certificate_days' => $product->typical_certificate_days,
                    'product_type_typical_certificate_days' => $product->productType->typical_certificate_days,
                ];
            });
        $asset->load([
            'images',
            'tickets.asset.product.brand',
            'tickets.asset.product.productType',
            'product.brand',
            'product.images',
            'product.productType',
            'product.productables.childProduct.brand',
            'product.productables.childProduct.productType',
            'product.productables.productRelation',
            'customer',
            'servicejobs',
            'customFields',
            'childAssetRelations.childAsset.product.brand',
            'childAssetRelations.childAsset.product.productType',
            'childAssetRelations.productable.productRelation',
            'parentAssetRelations.parentAsset.product.brand',
            'parentAssetRelations.parentAsset.product.productType',
            'parentAssetRelations.productable.productRelation',
            'maintenanceContracts.customer',
        ]);

        $currentTypeId = $asset->product?->productType?->id;
        $existingChildIds = $asset->childAssetRelations()->pluck('child_asset_id')->all();
        $eligibleChildAssets = [];

        $childTypeIds = $currentTypeId
            ? ProductType::query()->where('parent_id', $currentTypeId)->pluck('id')->all()
            : [];

        $productHasChildTypes = ! empty($childTypeIds);

        if ($productHasChildTypes) {
            $eligibleChildAssets = Asset::query()
                ->whereHas('product', fn($q) => $q->whereIn('product_type_id', $childTypeIds))
                ->where('customer_id', $asset->customer_id)
                ->whereNotIn('id', [...$existingChildIds, $asset->id])
                ->with(['product.brand', 'product.productType'])
                ->get()
                ->map(fn($a) => [
                    'id' => $a->id,
                    'name' => $a->product->brand->name . ' ' . $a->product->model
                        . ' (' . $a->product->productType->name . ')'
                        . ' — ' . ($a->product->bundle ? 'Bundel' : ($a->serial_number ?? 'geen serienr.')),
                ])
                ->values()
                ->all();
        }

        $customer_count = Customer::count();
        $product_count = Product::count();
        $preselected_customer = $asset->customer
            ? [['id' => $asset->customer->id, 'name' => $asset->customer->name]]
            : [];

        return inertia('Assets/ShowPage', [
            'asset' => $asset,
            'allProducts' => $product_count <= 50 ? $all_products : collect(),
            'productsUseAjax' => $product_count > 50,
            'allCustomers' => $customer_count <= 50
                ? Customer::orderBy('name')->get(['id', 'name'])
                : collect($preselected_customer),
            'customersUseAjax' => $customer_count > 50,
            'customFields' => $asset->allCustomFieldsWithValues(),
            'eligibleChildAssets' => $eligibleChildAssets,
            'productHasChildTypes' => $productHasChildTypes,
            'productRelations' => ProductRelation::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeChild(AssetChildStoreRequest $request, Asset $asset)
    {
        $productable = Productable::find($request->productable_id);

        DB::transaction(function () use ($asset, $productable, $request) {
            $child = Asset::create([
                'product_id' => $productable->productable_id,
                'customer_id' => $asset->customer_id,
                'serial_number' => $request->serial_number,
                'next_service_date' => null,
                'status' => 'Actief',
            ]);

            AssetRelation::create([
                'parent_asset_id' => $asset->id,
                'child_asset_id' => $child->id,
                'productable_id' => $productable->id,
                'product_relation_id' => $productable->product_relation_id,
            ]);
        });

        return redirect()->back()->with('success', 'Onderdeel-machine aangemaakt en gekoppeld.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AssetUpdateRequest $request, Asset $asset)
    {
        $asset->update($request->validated());

        return redirect()->route('assets.show', $asset->id)
            ->with('success', 'Machine bijgewerkt.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AssetDestroyRequest $request, Asset $asset)
    {
        $asset->delete();

        return redirect()
            ->route('assets.index')
            ->with('success', 'Machine verwijderd.');
    }
}
