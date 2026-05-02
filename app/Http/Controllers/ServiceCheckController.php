<?php

namespace App\Http\Controllers;

use App\Models\ProductType;
use App\Models\ServiceCheck;
use App\Models\ServiceCheckGroup;
use App\Http\Requests\ServiceCheckReadRequest;
use App\Enums\ServiceCheckTypes;
use App\Http\Requests\ServiceCheckStoreUpdateRequest;

class ServiceCheckController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ServiceCheckReadRequest $request)
    {
        $search = $request->get('search', '');
        $productType = $request->get('onlyType', null);
        $query = ServiceCheck::with(['productTypes', 'values', 'group']);

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($productType) {
            $query->whereHas('productTypes', function ($q) use ($productType) {
                $q->where('product_types.id', $productType);
            });
        }

        return inertia('ServiceChecks/IndexPage', [
            'serviceChecks'                => $query->orderBy('order')->paginate(10),
            'productTypes'                 => ProductType::flatListWithPath(),
            'groups'                       => ServiceCheckGroup::with('productTypes:id,name')->orderBy('order')->get(),
            'serviceCheckTypes'            => ServiceCheckTypes::assocArray(),
            'serviceCheckTypesWithOptions' => ServiceCheckTypes::getTypesWithOptions(),
            'search'                       => $search,
        ]);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(ServiceCheckStoreUpdateRequest $request)
    {
        $data = $request->validated();
        $productTypeIds = $data['product_type_ids'] ?? [];
        unset($data['product_type_ids']);
        $highestorder = ServiceCheck::whereHas('productTypes', function ($q) use ($productTypeIds) {
            if (count($productTypeIds)) {
                $q->whereIn('product_types.id', $productTypeIds);
            }
        })->max('order') ?? 0;
        $data['order'] = $highestorder + 1;

        $sc = ServiceCheck::create($data);
        if (count($productTypeIds)) {
            $sc->productTypes()->sync($productTypeIds);
        }
        $sc->load('productTypes', 'values', 'group');

        return redirect()->back()->with('success', 'Controlepunt is gemaakt');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ServiceCheckStoreUpdateRequest $request, ServiceCheck $servicecheck)
    {
        $data = $request->validated();
        $productTypeIds = $data['product_type_ids'] ?? [];
        unset($data['product_type_ids']);
        $servicecheck->update($data);
        if (!is_null($productTypeIds)) {
            $servicecheck->productTypes()->sync($productTypeIds);
        }
        $servicecheck->load('productTypes', 'values', 'group');

        return redirect()->back()->with('success', 'Controlepunt is aangepast');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ServiceCheck $servicecheck)
    {
        $servicecheck->delete();

        return redirect()->back();
    }
}
