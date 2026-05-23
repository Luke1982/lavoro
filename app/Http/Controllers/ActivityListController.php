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
        $days   = (int) $request->input('days', 60);
        $search = $request->filled('search') ? trim($request->input('search')) : null;

        return inertia('ActivityList/UpcomingActivities', [
            'upcomingAssets' => $this->buildCustomerAssetList('upcoming', $days, $search),
            'expiredAssets'  => $this->buildCustomerAssetList('expired', $days, $search),
            'eventTypes' => EventType::all(),
            'allUsers' => User::all(['id', 'name']),
        ]);
    }

    private function buildCustomerAssetList(string $type, int $days, ?string $search)
    {
        $matching_query = $type === 'upcoming'
            ? $this->getUpcomingAssetsQuery($days)
            : $this->getExpiredAssetsQuery();

        // Outer: one asset per customer, with customer eager-loaded. Drives ordering of customers on the page.
        $main_assets = $this->applySearchFilter($matching_query, $search)
            ->with(['customer'])
            ->orderBy('next_service_date')
            ->get()
            ->unique(fn($a) => $a->customer?->id)
            ->values();

        if ($main_assets->isEmpty()) {
            return collect();
        }

        $customer_ids = $main_assets->pluck('customer.id')->filter()->unique();

        // Inner: all relevant assets for those customers, with deep relations.
        // IMPORTANT: do NOT eager-load `customer` here. The outer "main asset" and the asset
        // inside customer.upcomingAssets with the same id must be different PHP instances —
        // otherwise Laravel's toArray() recursion guard drops the relations on the inner copy
        // and the Vue chokes on `asset.product.brand`.
        $inner_query = Asset::query()
            ->whereIn('customer_id', $customer_ids)
            ->where('status', 'Actief')
            ->with([
                'product.brand',
                'product.productType',
                'product.mainImage',
                'openTickets',
                'pendingTickets',
                'pendingServiceJobs.serviceOrder.pastOpenEvents',
                'pendingServiceJobs.serviceOrder.comingEvents',
            ]);

        if ($type === 'upcoming') {
            $inner_query
                ->where('next_service_date', '>=', now())
                ->where('next_service_date', '<=', now()->addDays($days))
                ->orderBy('next_service_date');
        } else {
            $inner_query
                ->where('next_service_date', '<', now())
                ->orderBy('next_service_date', 'desc');
        }

        $inner_by_customer = $inner_query->get()->groupBy('customer_id');

        foreach ($main_assets as $main) {
            if (!$main->customer) {
                continue;
            }
            $assets = $inner_by_customer->get($main->customer->id, collect());
            $assets->each(fn($a) => $this->attachEarlierPlannedEvents($a));

            $main->customer->upcoming_asset_days = $days;
            $main->customer->setRelation('upcomingAssets', $assets->values());
        }

        return $main_assets;
    }

    private function attachEarlierPlannedEvents(Asset $asset): void
    {
        $earlier = [];
        foreach ($asset->pendingServiceJobs as $job) {
            $order_id = $job->serviceOrder?->id;
            foreach (($job->serviceOrder?->pastOpenEvents ?? collect()) as $ev) {
                $earlier[] = [
                    'start' => Carbon::parse($ev->start)->toIso8601String(),
                    'service_order_id' => $order_id,
                    'event_id' => $ev->id,
                ];
            }
        }
        usort($earlier, fn($a, $b) => strcmp($b['start'], $a['start']));
        $asset->has_past_planned_event = !empty($earlier);
        $asset->earlier_planned_events = $earlier;
    }

    private function applySearchFilter(Builder $query, ?string $search): Builder
    {
        if (!$search) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->whereHas('customer', fn(Builder $c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhere('serial_number', 'like', "%{$search}%")
                ->orWhereHas('product', function (Builder $p) use ($search) {
                    $p->where('model', 'like', "%{$search}%")
                        ->orWhereHas('brand', fn(Builder $b) => $b->where('name', 'like', "%{$search}%"));
                });
        });
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
