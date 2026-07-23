<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssetAttachChildRequest;
use App\Http\Requests\AssetChildStoreRequest;
use App\Http\Requests\AssetDestroyRequest;
use App\Http\Requests\AssetDetachParentRequest;
use App\Http\Requests\AssetReadRequest;
use App\Http\Requests\AssetStoreRequest;
use App\Http\Requests\AssetTransferPreviewRequest;
use App\Http\Requests\AssetUpdateLocationRequest;
use App\Http\Requests\AssetUpdateRequest;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Productable;
use App\Models\ProductRelation;
use App\Models\ProductType;
use App\Services\AssetTransferService;
use App\Services\ProductableService;
use App\Traits\ReadsPerPage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
            'tickets as open_tickets_count' => fn ($q) => $q->where('status', 'Open'),
            'tickets as pending_tickets_count' => fn ($q) => $q->where('status', 'In behandeling'),
            'tickets as closed_tickets_count' => fn ($q) => $q->where('status', 'Gesloten'),
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

        $this->attachOwningCustomers($assets->getCollection());

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
                'location_id' => $validated['location_id'] ?? null,
                'serial_number' => $validated['serial_number'] ?? null,
                'next_service_date' => $validated['next_service_date'] ?? null,
                'date_in_service' => $validated['date_in_service'] ?? null,
                'status' => ($validated['is_active'] ?? true) ? 'Actief' : 'Niet actief',
            ]);

            foreach ($validated['child_assets'] ?? [] as $childData) {
                $productable = Productable::find($childData['productable_id']);
                if (!$productable || !$productable->is_required) {
                    continue;
                }

                Asset::create([
                    'product_id' => $productable->productable_id,
                    'customer_id' => null,
                    'parent_asset_id' => $asset->id,
                    'productable_id' => $productable->id,
                    'product_relation_id' => $productable->product_relation_id,
                    'serial_number' => $childData['serial_number'],
                    'next_service_date' => $validated['next_service_date'] ?? null,
                    'status' => ($validated['is_active'] ?? true) ? 'Actief' : 'Niet actief',
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
            'linkedLocation',
            'servicejobs',
            'customFields',
            'childAssets.product.brand',
            'childAssets.product.productType',
            'childAssets.productRelation',
            'parentAsset.product.brand',
            'parentAsset.product.productType',
            'parentAsset.productRelation',
            'maintenanceContracts.customer',
        ]);

        $currentTypeId = $asset->product?->productType?->id;
        $existingChildIds = $asset->childAssets()->pluck('id')->all();
        $eligibleChildAssets = [];

        $childTypeIds = $currentTypeId
            ? ProductType::query()->where('parent_id', $currentTypeId)->pluck('id')->all()
            : [];

        $productHasChildTypes = !empty($childTypeIds);

        if ($productHasChildTypes) {
            $eligibleChildAssets = Asset::query()
                ->whereHas('product', fn ($q) => $q->whereIn('product_type_id', $childTypeIds))
                ->where('customer_id', $asset->resolvedCustomerId())
                ->whereNotIn('id', [...$existingChildIds, $asset->id])
                ->with(['product.brand', 'product.productType'])
                ->get()
                ->map(fn ($a) => [
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

        /**
         * A child machine has no customer of its own — it is owned through the machine it
         * sits in — so the page is told who that is rather than reading customer_id off
         * the asset and finding null.
         */
        $root = $asset->rootAsset();
        $owning_customer = $root->customer;

        $preselected_customer = $owning_customer
            ? [['id' => $owning_customer->id, 'name' => $owning_customer->name]]
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
            'owningCustomer' => $owning_customer
                ? ['id' => $owning_customer->id, 'name' => $owning_customer->name]
                : null,
        ]);
    }

    public function storeChild(AssetChildStoreRequest $request, Asset $asset)
    {
        $productable = Productable::find($request->productable_id);

        Asset::create([
            'product_id' => $productable->productable_id,
            'customer_id' => null,
            'parent_asset_id' => $asset->id,
            'productable_id' => $productable->id,
            'product_relation_id' => $productable->product_relation_id,
            'serial_number' => $request->serial_number,
            'next_service_date' => null,
            'status' => 'Actief',
        ]);

        return redirect()->back()->with('success', 'Onderdeel-machine aangemaakt en gekoppeld.');
    }

    /**
     * Hangs an existing standalone machine under this one. It gives up its own customer
     * and location in the process — from here on it is owned through its parent.
     */
    public function attachChild(AssetAttachChildRequest $request, Asset $asset)
    {
        $child = Asset::findOrFail($request->validated('child_asset_id'));

        $child->update([
            'parent_asset_id' => $asset->id,
            'product_relation_id' => $request->validated('product_relation_id'),
            'customer_id' => null,
            'location_id' => null,
        ]);

        return redirect()->back()->with('success', 'Machine gekoppeld.');
    }

    /**
     * Feeds the transfer confirmation modal on the contract, werkbon and machine pages.
     * Resolves whichever record is having its customer changed down to the set of root
     * machines that would move, then asks the transfer service what that would entail.
     */
    public function transferPreview(AssetTransferPreviewRequest $request)
    {
        $subject = $request->subject();
        $new_customer_id = (int) $request->validated('customer_id');

        $roots = match ($request->validated('context')) {
            'contract' => $subject->assets->map->rootAsset(),
            'serviceorder' => $subject->serviceJobs()->with('asset')->get()
                ->pluck('asset')->filter()->map->rootAsset(),
            'asset' => collect([$subject->rootAsset()]),
        };

        $roots = $roots->unique('id')->values();

        $service = app(AssetTransferService::class);

        return response()->json(array_merge(
            $service->preview($roots, $new_customer_id),
            [
                'target_locations' => Location::where('customer_id', $new_customer_id)
                    ->orderBy('title')
                    ->get()
                    ->map(fn (Location $location) => [
                        'id' => $location->id,
                        'label' => $location->title ?: $location->addressLine(),
                    ])
                    ->values()
                    ->all(),
            ]
        ));
    }

    /**
     * Cuts a machine loose from its parent. Because a machine must be owned either by a
     * customer or by a parent, detaching is also an ownership decision: it inherits the
     * customer and location of the root it used to hang under.
     */
    public function detachParent(AssetDetachParentRequest $request, Asset $asset)
    {
        if ($asset->parent_asset_id === null) {
            return redirect()->back()->with('error', 'Deze machine hangt niet onder een andere machine.');
        }

        $root = $asset->rootAsset();

        $asset->update([
            'parent_asset_id' => null,
            'product_relation_id' => null,
            'productable_id' => null,
            'customer_id' => $root->customer_id,
            'location_id' => $root->location_id,
        ]);

        return redirect()->back()->with('success', 'Koppeling verwijderd.');
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
        $validated = $request->validated();

        $asset_strategy = $validated['asset_strategy'] ?? null;
        $location_map = $validated['location_map'] ?? [];
        unset($validated['asset_strategy'], $validated['location_map']);

        if ($asset_strategy === 'transfer') {
            $new_customer_id = (int) $validated['customer_id'];
            unset($validated['customer_id'], $validated['location_id']);

            $asset->update($validated);

            app(AssetTransferService::class)->transfer(
                collect([$asset->refresh()]),
                $new_customer_id,
                $location_map
            );

            return redirect()->route('assets.show', $asset->id)
                ->with('success', 'Machine bijgewerkt en overgedragen aan de nieuwe klant.');
        }

        $asset->update($validated);

        return redirect()->route('assets.show', $asset->id)
            ->with('success', 'Machine bijgewerkt.');
    }

    public function updateLocation(AssetUpdateLocationRequest $request, Asset $asset)
    {
        $asset->update(['location_id' => $request->validated()['location_id'] ?? null]);

        return redirect()->back()->with('success', 'Locatie van de machine bijgewerkt.');
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

    /**
     * A child machine has no customer of its own — it is owned through the machine it
     * sits in — so every row is told who its owner is instead of the page reading a
     * null customer off the asset. Ancestors are resolved one depth level at a time so
     * the walk up costs a query per level rather than a query per row.
     *
     * @param  EloquentCollection<int, Asset>  $assets
     */
    private function attachOwningCustomers(EloquentCollection $assets): void
    {
        foreach ($assets as $asset) {
            $asset->setAttribute('owning_customer', $asset->customer
                ? ['id' => $asset->customer->id, 'name' => $asset->customer->name]
                : null);
        }

        $pending = $assets->whereNull('customer_id')->keyBy('id');

        $frontier = $pending
            ->map(fn (Asset $asset) => $asset->parent_asset_id)
            ->filter();

        while ($frontier->isNotEmpty()) {
            $ancestors = Asset::query()
                ->whereIn('id', $frontier->unique()->values())
                ->with('customer:id,name')
                ->get(['id', 'customer_id', 'parent_asset_id'])
                ->keyBy('id');

            $next = collect();

            foreach ($frontier as $asset_id => $ancestor_id) {
                $ancestor = $ancestors->get($ancestor_id);

                if (!$ancestor) {
                    continue;
                }

                if ($ancestor->customer) {
                    $pending[$asset_id]->setAttribute('owning_customer', [
                        'id' => $ancestor->customer->id,
                        'name' => $ancestor->customer->name,
                    ]);

                    continue;
                }

                $next[$asset_id] = $ancestor->parent_asset_id;
            }

            $frontier = $next->filter();
        }
    }
}
