<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerSearchRequest;
use App\Http\Requests\LocationSearchRequest;
use App\Http\Requests\MaterialSearchRequest;
use App\Http\Requests\ProductSearchRequest;
use App\Http\Requests\ServiceOrderSearchRequest;
use App\Http\Requests\SupplierSearchRequest;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Supplier;
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
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->city ? "{$c->name} – {$c->city}" : $c->name,
            ]);

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
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'created_at']);

        return response()->json($results);
    }

    public function locationsForCustomer(LocationSearchRequest $request, Customer $customer): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $results = $customer->locations()
            ->when($q !== '', fn ($query) => $query->where(fn ($sub) => $sub
                ->where('title', 'like', "%{$q}%")
                ->orWhere('location_code', 'like', "%{$q}%")
                ->orWhere('city', 'like', "%{$q}%")))
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
