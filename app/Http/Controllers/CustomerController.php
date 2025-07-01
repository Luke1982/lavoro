<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $customers = Customer::with(['upcomingAssets', 'openTickets', 'pendingTickets', 'closedTickets'])->get();
        return inertia('Customers/IndexPage', [
            'customers' => $customers,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer)
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
            'closedTickets'
        ]);

        $upcomingByType    = $customer->upcomingAssets->groupBy('product.productType.name')->sortKeys();
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
    public function update(Request $request, Customer $customer)
    {
        $request->validate([
            'billing_customer_id' => 'required|exists:customers,id',
        ]);

        $customer->update($request->only(['billing_customer_id']));

        return redirect()->route('customers.show', $customer)->with('success', 'Facturatieklant ingesteld.');
    }
}
