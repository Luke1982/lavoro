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
            'upcomingAssets.tickets',
            'assets.product.brand',
            'assets.product.productType',
            'assets.tickets',
            'openTickets',
            'pendingTickets',
            'closedTickets'
        ]);
        return inertia('Customers/ShowPage', [
            'customer' => $customer,
        ]);
    }
}
