<?php

namespace App\Http\Controllers;

use App\Domain\Search\SearchTerm;
use App\Http\Requests\AssetSearchRequest;
use App\Http\Requests\CustomerSearchRequest;
use App\Http\Requests\EventTypeSearchRequest;
use App\Http\Requests\LocationSearchRequest;
use App\Http\Requests\MaterialSearchRequest;
use App\Http\Requests\ProductSearchRequest;
use App\Http\Requests\ServiceOrderSearchRequest;
use App\Http\Requests\SupplierSearchRequest;
use App\Http\Requests\UserSearchRequest;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\EventType;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ComboSearchController extends Controller
{
    public function customers(CustomerSearchRequest $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $results = Customer::query()
            ->when($q !== '', fn ($query) => $query->where('name', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name', 'city'])
            ->map->toComboOption();

        return response()->json($results);
    }

    /**
     * Machines om een storing aan te hangen. Alleen wat deze gebruiker mag zien:
     * de zoeker mag geen machines tonen die de lijst zelf zou verbergen.
     *
     * De machines gaan er heel uit en niet als id met naam: aan de andere kant
     * staat AssetSelectMenu, en die toont merk, model, serienummer en locatie.
     * mapAssetForSelect maakt daar in de browser het juiste van.
     */
    public function assets(AssetSearchRequest $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $results = Asset::visibleTo($request->user())
            ->when($q !== '', fn ($query) => $query->where('serial_number', 'like', "%{$q}%"))
            ->when(
                $request->filled('customer_id'),
                fn ($query) => $query->where('customer_id', $request->integer('customer_id'))
            )
            ->with(['product.brand', 'product.productType', 'linkedLocation', 'childAssets.product.brand'])
            ->orderBy('serial_number')

            /*
             * Op één klant mag de lijst compleet zijn: AssetSelectMenu zoekt in wat
             * het krijgt, dus wat hier wegvalt is aan de andere kant onvindbaar. Het
             * machinepark van één klant is te overzien; dat van iedereen niet.
             */
            ->limit($request->filled('customer_id') ? 200 : 25)
            ->get();

        return response()->json($results);
    }

    public function users(UserSearchRequest $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $results = User::query()
            ->when($q !== '', fn ($query) => $query->where('name', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name']);

        return response()->json($results);
    }

    public function eventTypes(EventTypeSearchRequest $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $results = EventType::query()
            ->when($q !== '', fn ($query) => $query->where('name', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name']);

        return response()->json($results);
    }

    public function materials(MaterialSearchRequest $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $results = Material::query()
            ->when($q !== '', fn ($query) => $query->where('name', 'like', "%{$q}%"))
            ->with('usageUnit')
            ->orderBy('name')
            ->limit(25)
            ->get();

        return response()->json($results);
    }

    public function products(ProductSearchRequest $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $results = Product::query()
            ->when(
                $q !== '',
                fn ($query) => $query->where('model', 'like', "%{$q}%")
                    ->orWhereHas('brand', fn ($bq) => $bq->where('name', 'like', "%{$q}%"))
            )
            ->with(['brand', 'productType'])
            ->orderBy('model')
            ->limit(25)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => "{$p->brand->name} {$p->model} ({$p->productType->name})",
                'bundle' => $p->bundle,
                'registable' => $p->registable,
                'typical_certificate_days' => $p->typical_certificate_days,
                'product_type_typical_certificate_days' => $p->productType->typical_certificate_days,
            ]);

        return response()->json($results);
    }

    public function suppliers(SupplierSearchRequest $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $results = Supplier::query()
            ->when($q !== '', fn ($query) => $query->where('name', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name']);

        return response()->json($results);
    }

    public function serviceOrders(ServiceOrderSearchRequest $request): JsonResponse
    {
        $user = $request->user();
        $customer_id = (int) $request->validated()['customer_id'];
        $include_id = $request->validated()['include_id'] ?? null;
        $can_read_all = $user->isAdmin() || $user->hasPermission('serviceorder.read');

        $results = ServiceOrder::query()
            ->where('customer_id', $customer_id)
            ->where(function ($query) use ($include_id) {
                $query->doesntHave('events');
                if ($include_id) {
                    $query->orWhere('id', $include_id);
                }
            })
            ->when(!$can_read_all, fn ($query) => $query->whereHas(
                'executingUsers',
                fn ($uq) => $uq->where('users.id', $user->id)
            ))
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'order_date']);

        return response()->json($results);
    }

    public function locationsForCustomer(LocationSearchRequest $request, Customer $customer): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $results = $customer->locations()
            ->when($term !== '', fn ($query) => $query->matchesText(SearchTerm::like($term)))
            ->orderBy('title')
            ->limit(50)
            ->get(['id', 'title', 'address', 'postal_code', 'city'])
            ->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->city ? "{$l->title} – {$l->city}" : $l->title,
                'address' => $l->address,
                'postal_code' => $l->postal_code,
                'city' => $l->city,
            ]);

        return response()->json($results);
    }
}
