<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

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

        return inertia('Customers/ShowPage', [
            'customer' => $customer,
            'upcomingAssetsByType' => $upcomingByType,
            'nonUpcomingAssetsByType' => $nonUpcomingByType,
        ]);
    }
}
