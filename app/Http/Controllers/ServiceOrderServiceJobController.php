<?php

namespace App\Http\Controllers;

use App\Actions\ServiceOrders\AddServiceJobsAction;
use App\Http\Requests\ServiceOrderServiceJobStoreRequest;
use App\Models\ServiceOrder;

/**
 * Keuringen for every active machine of the werkbon's customer, or for those at
 * one location, in one go.
 */
class ServiceOrderServiceJobController extends Controller
{
    public function store(
        ServiceOrderServiceJobStoreRequest $request,
        ServiceOrder $serviceorder,
        AddServiceJobsAction $add,
    ) {
        ['jobs' => $jobs, 'parts' => $parts] = $add->forCustomerMachines(
            $serviceorder,
            $request->integer('location_id') ?: null,
        );

        return redirect()->back()->with('success', $jobs->count() === 1
            ? '1 keuring aangemaakt.'
            : $jobs->count() . ' keuringen aangemaakt.' . ($parts ? ' Plus ' . count($parts) . ' voor onderdelen.' : ''));
    }
}
