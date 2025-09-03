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

        $query = ServiceCheckGroup::with(['productType']);

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($productType) {
            $query->where('product_type_id', $productType);
        }

        return inertia('ServiceCheckGroups/IndexPage', [
            'groups'       => $query->orderBy('order')->paginate(10),
            'productTypes' => ProductType::all(),
            'search'       => $search,
        ]);
    }

    public function store(ServiceCheckGroupStoreUpdateRequest $request)
    {
        $highest = ServiceCheckGroup::where('product_type_id', $request->product_type_id)->max('order') ?? 0;
        $data = $request->validated();
        $data['order'] = $highest + 1;

        $group = ServiceCheckGroup::create($data)->load('productType');

        return redirect()->route('servicecheckgroups.index')->with([
            'success' => 'Groep is aangemaakt',
            'extra'   => $group,
        ]);
    }

    public function update(ServiceCheckGroupStoreUpdateRequest $request, ServiceCheckGroup $servicecheckgroup)
    {
        $servicecheckgroup->update($request->validated());
        $servicecheckgroup->load('productType');

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
