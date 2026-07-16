<?php

namespace App\Http\Controllers;

use App\Http\Requests\LocationDestroyRequest;
use App\Http\Requests\LocationReadRequest;
use App\Http\Requests\LocationStoreRequest;
use App\Http\Requests\LocationUpdateCoordsRequest;
use App\Http\Requests\LocationUpdateRequest;
use App\Models\Location;

class LocationController extends Controller
{
    public function index(LocationReadRequest $request)
    {
        $search = $request->input('search');
        $customer_id = $request->input('customer_id');

        $locations = Location::with('customer:id,name')
            ->withCount(['assets', 'serviceOrders'])
            ->when($customer_id, fn ($query) => $query->where('customer_id', $customer_id))
            ->when($search !== null && $search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('location_code', 'like', "%{$search}%")
                ->orWhere('address', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))))
            ->orderBy('title')
            ->paginate(25)
            ->appends(['search' => $search, 'customer_id' => $customer_id]);

        return inertia('Locations/IndexPage', [
            'locations' => $locations,
            'filters' => ['search' => $search, 'customer_id' => $customer_id],
        ]);
    }

    public function show(LocationReadRequest $request, Location $location)
    {
        $location->load([
            'customer:id,name',
            'assets.product.brand',
            'assets.product.productType',
        ])->loadCount('serviceOrders');

        return inertia('Locations/ShowPage', [
            'location' => $location,
        ]);
    }

    public function store(LocationStoreRequest $request)
    {
        Location::create($request->sanitized());

        return redirect()->back()->with('success', 'Locatie aangemaakt.');
    }

    public function update(LocationUpdateRequest $request, Location $location)
    {
        $location->update($request->sanitized());

        return redirect()->back()->with('success', 'Locatie bijgewerkt.');
    }

    public function destroy(LocationDestroyRequest $request, Location $location)
    {
        $disposition = $request->input('disposition', 'detach');
        $target = $disposition === 'move' ? $request->input('target_location_id') : null;

        $location->assets()->update(['location_id' => $target]);
        $location->serviceOrders()->update(['location_id' => $target]);

        $location->delete();

        return redirect()->route('locations.index')->with('success', 'Locatie verwijderd.');
    }

    public function updateCoords(LocationUpdateCoordsRequest $request, Location $location)
    {
        $location->update($request->validated());

        return response()->json(['ok' => true]);
    }
}
