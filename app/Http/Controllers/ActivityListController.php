<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatusses;
use App\Models\Asset;
use App\Models\Customer;
use App\Http\Requests\ActivityListReadRequest;
use App\Models\EventType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ActivityListController extends Controller
{
    public function getUpcomingActivities(ActivityListReadRequest $request)
    {
        $days = (int)$request->input('days', 60);

        $upcoming_assets = $this->getAssetsForListView($this->getUpcomingAssetsQuery($days));
        $expired_assets = $this->getAssetsForListView($this->getExpiredAssetsQuery());

        $this->prepareAssetData($upcoming_assets, $days, 'upcoming');
        $this->prepareAssetData($expired_assets, $days, 'expired');

        return inertia('ActivityList/UpcomingActivities', [
            'upcomingAssets' => $upcoming_assets,
            'expiredAssets' => $expired_assets,
            'eventTypes' => EventType::all(),
            'allUsers' => User::all(['id', 'name']),
        ]);
    }

    private function getAssetsForListView(Builder $query)
    {
        return $query
            ->with(['customer'])
            ->orderBy('next_service_date')
            ->get()
            ->unique(fn ($asset) => $asset->customer?->id)
            ->values();
    }

    private function prepareAssetData($assets, int $days, string $type)
    {
        foreach ($assets as $asset) {
            if ($asset->customer) {
                $asset->customer->upcoming_asset_days = $days;

                $asset_query = match ($type) {
                    'upcoming' => $asset->customer->upcomingAssets(),
                    'expired' => $asset->customer->expiredAssets(),
                    default => $asset->customer->upcomingAssets(),
                };

                $asset->customer->setRelation(
                    'upcomingAssets',
                    $asset_query->with([
                        'product.brand',
                        'openTickets',
                        'pendingTickets',
                        'product.productType',
                        'pendingServiceJobs.serviceOrder.events'
                    ])->get()
                );
            }
        }
    }

    private function getUpcomingAssetsQuery(int $days)
    {
        return Asset::upcomingAndUnplanned($days);
    }

    private function getExpiredAssetsQuery()
    {
        return Asset::expired();
    }

    public function map(ActivityListReadRequest $request)
    {
        $days = (int)$request->input('days', 60);

        $upcoming_customer_ids = $this->getUpcomingAssetsQuery($days)
            ->whereNotNull('customer_id')
            ->pluck('customer_id');

        $expired_customer_ids = $this->getExpiredAssetsQuery()
            ->whereNotNull('customer_id')
            ->pluck('customer_id');

        $customer_ids = $upcoming_customer_ids->merge($expired_customer_ids)->unique();

        $customers = Customer::whereIn('id', $customer_ids)->with(['assets' => function ($q) {
            $q->select('id', 'customer_id', 'next_service_date', 'status', 'serial_number', 'product_id')
              ->with(['product.productType']);
        }])->get(['id', 'name', 'address', 'postal_code', 'city', 'lat', 'lon']);

        $now = Carbon::now();
        $customers->transform(function ($c) use ($now, $expired_customer_ids) {
            $c->has_expired_assets = $expired_customer_ids->contains($c->id);
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
