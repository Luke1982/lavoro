<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerSearchRequest;
use App\Http\Requests\MaterialSearchRequest;
use App\Http\Requests\ProductSearchRequest;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
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
            ->when($q !== '', fn ($query) => $query->where('model', 'like', "%{$q}%")
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
}
