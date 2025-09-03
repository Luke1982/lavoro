<?php

namespace App\Http\Controllers;

use App\Models\ProductType;
use App\Models\ServiceCheck;
use App\Models\ServiceCheckGroup;
use Illuminate\Http\Request;
use App\Enums\ServiceCheckTypes;
use App\Http\Requests\ServiceCheckStoreUpdateRequest;

class ServiceCheckController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->get('search', '');
        $productType = $request->get('onlyType', null);
    $query = ServiceCheck::with(['productType', 'values', 'group']);

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($productType) {
            $query->where('product_type_id', $productType);
        }

        return inertia('ServiceChecks/IndexPage', [
            'serviceChecks'                => $query->orderBy('order')->paginate(10),
            'productTypes'                 => ProductType::all(),
            'groups'                       => ServiceCheckGroup::select('id', 'name', 'product_type_id')
                ->orderBy('product_type_id')
                ->orderBy('order')
                ->get(),
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
        $highestorder = ServiceCheck::where('product_type_id', $request->product_type_id)
        ->max('order') ?? 0;
        $data = $request->validated();
        $data['order'] = $highestorder + 1;

        $sc = ServiceCheck::create($data)
            ->load('productType', 'values', 'group');

            return redirect()->route('servicechecks.index')->with([
                'success' => 'Controlepunt is gemaakt',
                'extra'   => $sc,
            ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ServiceCheckStoreUpdateRequest $request, ServiceCheck $servicecheck)
    {
        $servicecheck->update($request->validated());
    $servicecheck->load('productType', 'values', 'group');

        return redirect()->route('servicechecks.index')->with([
            'success' => 'Controlepunt is aangepast',
            'extra'   => $servicecheck,
        ]);
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
