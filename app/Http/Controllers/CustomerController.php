<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Http\Requests\CustomerReadRequest;
use App\Http\Requests\CustomerStoreRequest;
use App\Http\Requests\CustomerUpdateRequest;
use App\Http\Requests\CustomerUpdateCoordsRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(CustomerReadRequest $request)
    {
        $search = $request->input('search');
        $customers = Customer::with(['upcomingAssets', 'openTickets', 'pendingTickets', 'closedTickets'])
            ->when($search !== null && $search !== '', fn($query) =>
                $query->where(fn($q) =>
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('invoice_email', 'like', "%{$search}%")
                        ->orWhere('quotes_email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%")
                        ->orWhere('website', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhere('postal_code', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('country', 'like', "%{$search}%")
                        ->orWhere('postal_address', 'like', "%{$search}%")
                        ->orWhere('postal_postal_code', 'like', "%{$search}%")
                        ->orWhere('postal_city', 'like', "%{$search}%")
                        ->orWhere('postal_country', 'like', "%{$search}%")
                        ->orWhere('location_code', 'like', "%{$search}%")))
            ->get();
        return inertia('Customers/IndexPage', [
            'customers' => $customers,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(CustomerReadRequest $request, Customer $customer)
    {
        $customer->load([
            'upcomingAssets.product.brand',
            'upcomingAssets.product.productType',
            'upcomingAssets.openTickets',
            'upcomingAssets.pendingTickets',
            'upcomingAssets.closedTickets',
            'nonUpcomingAssets.product.brand',
            'nonUpcomingAssets.product.productType',
            'nonUpcomingAssets.openTickets',
            'nonUpcomingAssets.pendingTickets',
            'nonUpcomingAssets.closedTickets',
            'openTickets',
            'pendingTickets',
            'closedTickets',
            'serviceOrders.serviceJobs.asset.tickets',
            'serviceOrders.events.eventType',
            'serviceOrders.events.executingUsers:id,name',
            'serviceOrders.events.owners:id,name',
        ]);

        $user = Auth::user();
        $has_all = $user?->is_admin || $user?->permissions()->where('name', 'event.see_all')->exists();
        if (!$has_all) {
            foreach ($customer->serviceOrders as $order) {
                $order->setRelation('events', $order->events->filter(function ($e) use ($user) {
                    $executing_ids = $e->executingUsers->pluck('id')->all();
                    $owner_ids = $e->owners->pluck('id')->all();
                    return in_array($user->id, $executing_ids) || in_array($user->id, $owner_ids);
                })->values());
            }
        }

        $upcomingByType = $customer->upcomingAssets->groupBy('product.productType.name')->sortKeys();
        $nonUpcomingByType = $customer->nonUpcomingAssets->groupBy('product.productType.name')->sortKeys();

        $allCustomers = Customer::select(
            'id',
            DB::raw("CONCAT_WS(' – ', name, city) as name")
        )
        ->orderBy('name')
        ->get();

        return inertia('Customers/ShowPage', [
            'customer' => $customer,
            'upcomingAssetsByType' => $upcomingByType,
            'nonUpcomingAssetsByType' => $nonUpcomingByType,
            'allCustomers' => $allCustomers,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CustomerUpdateRequest $request, Customer $customer)
    {
        $customer->update($request->validated());

        return redirect()->route('customers.show', $customer)->with('success', 'Facturatieklant ingesteld.');
    }

    /**
     * Store a newly created customer.
     */
    public function store(CustomerStoreRequest $request)
    {
        $data = $request->sanitized();

        if (empty($data['snelstart_id'] ?? null)) {
            $data['snelstart_id'] = (string) \Illuminate\Support\Str::uuid();
        }

        $customer = Customer::create($data);

        return redirect()->route('customers.index')->with('success', 'Klant aangemaakt.');
    }

    public function updateCoords(CustomerUpdateCoordsRequest $request, Customer $customer)
    {
        $customer->update($request->validated());
        return response()->json(['ok' => true]);
    }
}
