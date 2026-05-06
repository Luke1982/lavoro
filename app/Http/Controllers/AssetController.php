<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetRelation;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Productable;
use App\Models\ProductType;
use App\Models\ProductRelation;
use App\Http\Requests\AssetChildStoreRequest;
use App\Http\Requests\AssetReadRequest;
use App\Http\Requests\AssetStoreRequest;
use App\Http\Requests\AssetUpdateRequest;
use App\Http\Requests\AssetDestroyRequest;
use App\Services\ProductableService;
use Illuminate\Support\Facades\DB;

// duplicate imports removed

class AssetController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    /**
     * List assets with optional search filter.
     *
     * @param AssetReadRequest $request
     * @return \Inertia\Response
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
        ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('product', function ($q2) use ($search) {
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
            ->paginate(20)
            ->appends(['search' => $search]);

        $all_products = Product::with(['brand', 'productType'])
            ->join('product_types', 'products.product_type_id', '=', 'product_types.id')
            ->orderBy('product_types.name', 'ASC')
            ->select('products.*')
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->brand->name . ' ' . $product->model . ' (' . $product->productType->name . ')',
                ];
            });

        $all_customers = Customer::orderBy('name', 'ASC')
            ->get(['id', 'name'])
            ->map(function ($c) {
                return ['id' => $c->id, 'name' => $c->name];
            });

        return inertia('Assets/IndexPage', [
            'assets'        => $assets,
            'initialSearch' => $search,
            'allProducts'   => $all_products,
            'allCustomers'  => $all_customers,
            'requiredProductablesByProduct' => ProductableService::requiredProductablesMap(),
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
                'product_id'        => $validated['product_id'],
                'customer_id'       => $validated['customer_id'],
                'serial_number'     => $validated['serial_number'] ?? null,
                'next_service_date' => $validated['next_service_date'] ?? null,
                'status'            => ($validated['is_active'] ?? true) ? 'Actief' : 'Niet actief',
            ]);

            foreach ($validated['child_assets'] ?? [] as $childData) {
                $productable = Productable::find($childData['productable_id']);
                if (!$productable || !$productable->is_required) {
                    continue;
                }

                $childAsset = Asset::create([
                    'product_id'        => $productable->productable_id,
                    'customer_id'       => $validated['customer_id'],
                    'serial_number'     => $childData['serial_number'],
                    'next_service_date' => $validated['next_service_date'] ?? null,
                    'status'            => ($validated['is_active'] ?? true) ? 'Actief' : 'Niet actief',
                ]);

                AssetRelation::create([
                    'parent_asset_id'     => $asset->id,
                    'child_asset_id'      => $childAsset->id,
                    'productable_id'      => $productable->id,
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
     * @param AssetReadRequest $request
     * @param Asset $asset
     * @return \Inertia\Response
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
                    'name' => $product->brand->name . ' ' . $product->model . ' (' . $product->productType->name . ')',
                ];
            });
        $asset->load([
            'images',
            'tickets',
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
        ]);

        $currentTypeId    = $asset->product?->productType?->id;
        $existingChildIds = $asset->childAssetRelations()->pluck('child_asset_id')->all();
        $eligibleChildAssets  = [];

        $childTypeIds = $currentTypeId
            ? ProductType::query()->where('parent_id', $currentTypeId)->pluck('id')->all()
            : [];

        $productHasChildTypes = !empty($childTypeIds);

        if ($productHasChildTypes) {
            $eligibleChildAssets = Asset::query()
                ->whereHas('product', fn($q) => $q->whereIn('product_type_id', $childTypeIds))
                ->where('customer_id', $asset->customer_id)
                ->whereNotIn('id', [...$existingChildIds, $asset->id])
                ->with(['product.brand', 'product.productType'])
                ->get()
                ->map(fn($a) => [
                    'id'   => $a->id,
                    'name' => $a->product->brand->name . ' ' . $a->product->model
                        . ' (' . $a->product->productType->name . ')'
                        . ' — ' . ($a->serial_number ?? 'geen serienr.'),
                ])
                ->values()
                ->all();
        }

        return inertia('Assets/ShowPage', [
            'asset'               => $asset,
            'allProducts'         => $all_products,
            'allCustomers'        => Customer::orderBy('name')->get(['id', 'name']),
            'customFields'        => $asset->allCustomFieldsWithValues(),
            'eligibleChildAssets'    => $eligibleChildAssets,
            'productHasChildTypes'   => $productHasChildTypes,
            'productRelations'       => ProductRelation::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeChild(AssetChildStoreRequest $request, Asset $asset)
    {
        $productable = Productable::find($request->productable_id);

        DB::transaction(function () use ($asset, $productable, $request) {
            $child = Asset::create([
                'product_id'        => $productable->productable_id,
                'customer_id'       => $asset->customer_id,
                'serial_number'     => $request->serial_number,
                'next_service_date' => null,
                'status'            => 'Actief',
            ]);

            AssetRelation::create([
                'parent_asset_id'     => $asset->id,
                'child_asset_id'      => $child->id,
                'productable_id'      => $productable->id,
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
            ->back()
            ->with('success', 'Machine verwijderd.');
    }
}
