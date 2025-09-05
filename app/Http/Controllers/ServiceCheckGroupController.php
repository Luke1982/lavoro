<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceCheckGroupStoreUpdateRequest;
use App\Models\ProductType;
use App\Models\ServiceCheckGroup;
use Illuminate\Http\Request;

class ServiceCheckGroupController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search', '');
        $productType = $request->get('onlyType', null);
        $query = ServiceCheckGroup::with(['productTypes']);

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($productType) {
            $query->whereHas('productTypes', function ($q) use ($productType) {
                $q->where('product_types.id', $productType);
            });
        }

        return inertia('ServiceCheckGroups/IndexPage', [
            'groups'       => $query->orderBy('order')->paginate(10),
            'productTypes' => ProductType::all(),
            'search'       => $search,
        ]);
    }

    public function store(ServiceCheckGroupStoreUpdateRequest $request)
    {
        $data = $request->validated();
        $productTypeIds = $data['product_type_ids'] ?? [];
        unset($data['product_type_ids']);
        $highest = ServiceCheckGroup::max('order') ?? 0;
        $data['order'] = $highest + 1;

        $group = ServiceCheckGroup::create($data);
        if (count($productTypeIds)) {
            $group->productTypes()->sync($productTypeIds);
        }
        $group->load('productTypes');

        return redirect()->route('servicecheckgroups.index')->with([
            'success' => 'Groep is aangemaakt',
            'extra'   => $group,
        ]);
    }

    public function update(ServiceCheckGroupStoreUpdateRequest $request, ServiceCheckGroup $servicecheckgroup)
    {
        $data = $request->validated();
        $productTypeIds = $data['product_type_ids'] ?? [];
        unset($data['product_type_ids']);
        $servicecheckgroup->update($data);
        if (!is_null($productTypeIds)) {
            $servicecheckgroup->productTypes()->sync($productTypeIds);
        }
        $servicecheckgroup->load('productTypes');

        return redirect()->route('servicecheckgroups.index')->with([
            'success' => 'Groep is bijgewerkt',
            'extra'   => $servicecheckgroup,
        ]);
    }

    public function destroy(ServiceCheckGroup $servicecheckgroup)
    {
        $servicecheckgroup->delete();
        return redirect()->back();
    }
}
