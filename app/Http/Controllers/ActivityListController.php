<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatusses;
use App\Models\Asset;
use Illuminate\Http\Request;

class ActivityListController extends Controller
{
    public function getUpcomingActivities(Request $request)
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
}
