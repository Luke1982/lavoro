<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatusses;
use App\Models\Asset;
use App\Models\Customer;
use App\Http\Requests\ActivityListReadRequest;
use Carbon\Carbon;

class ActivityListController extends Controller
{
    public function getUpcomingActivities(ActivityListReadRequest $request)
    {
        $days = (int)$request->input('days', 60);
        $upcoming_assets = Asset::where('next_service_date', '<', now()->addDays($days))
            ->where('status', '!=', AssetStatusses::inactive->value)
            ->with(['customer' ])
            ->orderBy('next_service_date')
            ->get()
            ->unique(fn ($asset) => $asset->customer?->id)
            ->values();

        foreach ($upcoming_assets as $asset) {
            if ($asset->customer) {
                $asset->customer->upcoming_asset_days = $days;
                $asset->customer->setRelation(
                    'upcomingAssets',
                    $asset->customer->upcomingAssets()->with([
                        'product.brand',
                        'openTickets',
                        'pendingTickets',
                        'product.productType',
                        'pendingServiceJobs.serviceOrder.events'
                    ])->get()
                );
            }
        }

        return inertia('ActivityList/UpcomingActivities', [
            'upcomingAssets' => $upcoming_assets,
        ]);
    }

    public function map(ActivityListReadRequest $request)
    {
        $days = (int)$request->input('days', 60);

        $customer_ids = Asset::where('next_service_date', '<', now()->addDays($days))
            ->where('status', '!=', AssetStatusses::inactive->value)
            ->whereNotNull('customer_id')
            ->pluck('customer_id')
            ->unique();

        $customers = Customer::whereIn('id', $customer_ids)->with(['assets' => function ($q) {
            $q->select('id', 'customer_id', 'next_service_date', 'status', 'serial_number', 'product_id')
              ->with(['product.productType']);
        }])->get(['id', 'name', 'address', 'postal_code', 'city', 'lat', 'lon']);

        $now = Carbon::now();
        $customers->transform(function ($c) use ($now) {
            $eligible = $c->assets->filter(
                fn($a) => $a->next_service_date &&
                    $a->status !== AssetStatusses::inactive->value
            );
            $days = $eligible
                ->map(fn($a) => $now->diffInDays(Carbon::parse($a->next_service_date), false))
                ->min();
            $c->next_service_in_days = $days; // int|null
            // earliest asset info
            $earliest = $eligible->sortBy(fn($a) => $a->next_service_date)->first();
            if ($earliest) {
                $c->earliest_asset_serial = $earliest->serial_number;
                $c->earliest_asset_product_type = $earliest->product?->productType?->name;
            }
            return $c;
        });

        return inertia('ActivityList/UpcomingActivitiesMap', [
            'customers' => $customers,
        ]);
    }
}
