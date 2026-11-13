<?php

namespace App\Http\Controllers;

use App\Actions\ServiceOrders\AddServiceJobsAction;
use App\Exceptions\Refusal;
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
        throw_if(!$serviceorder->customer, Refusal::class, 'Deze werkbon heeft geen klant.');

        $machines = $serviceorder->customer->activeAssets()
            ->with(['product.brand', 'product.productType'])
            ->when($request->integer('location_id'), fn ($query, int $location_id) => $query->where('location_id', $location_id))
            ->get();

        ['jobs' => $jobs, 'parts' => $parts] = $add->execute($serviceorder, $machines);

        throw_if($jobs->isEmpty(), Refusal::class, 'Er zijn geen machines meer zonder keuring op deze werkbon.');

        return redirect()->back()->with('success', $jobs->count() === 1
            ? '1 keuring aangemaakt.'
            : $jobs->count() . ' keuringen aangemaakt.' . ($parts ? ' Plus ' . count($parts) . ' voor onderdelen.' : ''));
    }
}
