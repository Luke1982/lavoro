<?php

namespace App\Http\Controllers;

use App\Models\ProductType;
use App\Models\ServiceCheck;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Enums\ServiceCheckTypes;

class ServiceCheckController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->get('search', '');
        $productType = $request->get('onlyType', null);
        $query = ServiceCheck::with(['productType', 'values']);

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($productType) {
            $query->where('product_type_id', $productType);
        }

        return inertia('ServiceChecks/IndexPage', [
            'serviceChecks'                => $query->orderBy('id')->paginate(10),
            'productTypes'                 => ProductType::all(),
            'serviceCheckTypes'            => ServiceCheckTypes::assocArray(),
            'serviceCheckTypesWithOptions' => ServiceCheckTypes::getTypesWithOptions(),
            'search'                       => $search,
        ]);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'product_type_id' => 'required|exists:product_types,id',
            'type'            => ['required', Rule::in(array_column(ServiceCheckTypes::cases(), 'name'))],
        ]);

        $sc = ServiceCheck::create($validated)
            ->load('productType', 'values');

            return redirect()->route('servicechecks.index')->with('success', 'Controlepunt is gemaakt');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ServiceCheck $serviceCheck)
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'product_type_id' => 'required|exists:product_types,id',
            'type'            => ['required', Rule::in(array_column(ServiceCheckTypes::cases(), 'name'))],
        ]);

        $serviceCheck->update($validated);
        $serviceCheck->load('productType', 'values');

        return redirect()->route('servicechecks.index')->with('success', 'Controlepunt is aangepast');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ServiceCheck $serviceCheck)
    {
        $serviceCheck->delete();

        return response()->noContent();
    }
}
