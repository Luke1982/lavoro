<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatusses;
use App\Http\Requests\ActivityListReadRequest;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\EventType;
use App\Models\Location;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ActivityListController extends Controller
{
    private const MAX_PARENT_DEPTH = 10;

    public function getUpcomingActivities(ActivityListReadRequest $request)
    {
        $days = (int) $request->input('days', 60);
        $search = $request->filled('search') ? trim($request->input('search')) : null;

        return inertia('ActivityList/UpcomingActivities', [
            'upcomingAssets' => $this->buildCustomerAssetList('upcoming', $days, $search),
            'expiredAssets' => $this->buildCustomerAssetList('expired', $days, $search),
            'eventTypes' => EventType::all(),
            'allUsers' => User::all(['id', 'name']),
        ]);
    }

    private function buildCustomerAssetList(string $type, int $days, ?string $search)
    {
        $matching_query = $type === 'upcoming'
            ? $this->getUpcomingAssetsQuery($days)
            : $this->getExpiredAssetsQuery();

        // Outer: one asset per customer. Drives ordering of customers on the page.
        $candidates = $this->applySearchFilter($matching_query, $search)
            ->orderBy('next_service_date')
            ->get();

        $ownership = $this->resolveRootOwnership($candidates);

        $main_assets = $candidates
            ->filter(fn ($a) => isset($ownership[$a->id]))
            ->unique(fn ($a) => $ownership[$a->id]['customer_id'])
            ->values();

        if ($main_assets->isEmpty()) {
            return collect();
        }

        $customers = Customer::whereIn('id', $main_assets->map(fn ($a) => $ownership[$a->id]['customer_id'])->unique())
            ->get()
            ->keyBy('id');

        $main_assets = $main_assets
            ->filter(fn ($a) => $customers->has($ownership[$a->id]['customer_id']))
            ->values();

        if ($main_assets->isEmpty()) {
            return collect();
        }

        $customer_ids = $main_assets->map(fn ($a) => $ownership[$a->id]['customer_id'])->unique()->values();

        $child_ownership = collect($this->resolveRootOwnership(
            $this->applyServiceDateWindow(
                Asset::query()->whereNull('customer_id')->where('status', 'Actief'),
                $type,
                $days
            )->get(['id', 'customer_id', 'parent_asset_id', 'location_id'])
        ))->filter(fn ($root) => $customer_ids->contains($root['customer_id']));

        // Inner: all relevant assets for those customers, with deep relations.
        // IMPORTANT: do NOT eager-load `customer` here. The outer "main asset" and the asset
        // inside customer.upcomingAssets with the same id must be different PHP instances —
        // otherwise Laravel's toArray() recursion guard drops the relations on the inner copy
        // and the Vue chokes on `asset.product.brand`.
        $inner_query = Asset::query()
            ->where('status', 'Actief')
            ->where(fn (Builder $q) => $q
                ->whereIn('customer_id', $customer_ids)
                ->orWhereIn('id', $child_ownership->keys()))
            ->with([
                'product.brand',
                'product.productType',
                'product.mainImage',
                'linkedLocation',
                'openTickets',
                'pendingTickets',
                'pendingServiceJobs.serviceOrder.pastOpenEvents.executingUsers',
                'pendingServiceJobs.serviceOrder.comingEvents',
            ]);

        $inner_assets = $this->applyServiceDateWindow($inner_query, $type, $days)->get();

        $root_of = fn (Asset $asset) => $asset->customer_id !== null
            ? ['customer_id' => $asset->customer_id, 'location_id' => $asset->location_id]
            : $child_ownership->get($asset->id);

        $inner_by_customer = $inner_assets->groupBy(fn (Asset $a) => $root_of($a)['customer_id']);

        $locations = Location::whereIn(
            'id',
            $inner_assets->map(fn (Asset $a) => $root_of($a)['location_id'])->filter()->unique()
        )->get()->keyBy('id');

        foreach ($main_assets as $main) {
            $customer = $customers->get($ownership[$main->id]['customer_id']);
            $main->setRelation('customer', $customer);

            $assets = $inner_by_customer->get($customer->id, collect());
            $assets->each(fn ($a) => $this->attachEarlierPlannedEvents($a));

            $customer->upcoming_asset_days = $days;
            $customer->setRelation('upcomingAssets', $assets->values());

            $groups = $assets->groupBy(fn (Asset $a) => $root_of($a)['location_id'] ?? 0)
                ->map(function ($group, $location_id) use ($locations) {
                    $location = $locations->get($location_id);

                    return [
                        'location' => $location
                            ? $location->only(['id', 'title', 'address', 'postal_code', 'city'])
                            : null,
                        'asset_ids' => $group->pluck('id')->values(),
                    ];
                })->sortBy(fn ($group) => $group['location'] ? 0 : 1)->values();
            $customer->setAttribute('location_groups', $groups);
        }

        return $main_assets;
    }

    private function applyServiceDateWindow(Builder $query, string $type, int $days): Builder
    {
        if ($type === 'upcoming') {
            return $query
                ->where('next_service_date', '>=', now())
                ->where('next_service_date', '<=', now()->addDays($days))
                ->orderBy('next_service_date');
        }

        return $query
            ->where('next_service_date', '<', now())
            ->orderBy('next_service_date', 'desc');
    }

    /**
     * Maps each asset onto the customer and location of its root asset. A child asset carries
     * neither of its own, so ownership resolves up the parent chain — one query per level rather
     * than one per asset. Assets whose chain leads nowhere are left out entirely.
     *
     * @return array<int, array{customer_id: int, location_id: int|null}>
     */
    private function resolveRootOwnership(Collection $assets): array
    {
        $resolved = [];
        $pending = [];

        foreach ($assets as $asset) {
            if ($asset->customer_id !== null) {
                $resolved[$asset->id] = [
                    'customer_id' => $asset->customer_id,
                    'location_id' => $asset->location_id,
                ];
            } elseif ($asset->parent_asset_id !== null) {
                $pending[$asset->id] = $asset->parent_asset_id;
            }
        }

        for ($depth = 0; !empty($pending) && $depth < self::MAX_PARENT_DEPTH; $depth++) {
            $parents = Asset::whereIn('id', array_unique(array_values($pending)))
                ->get(['id', 'customer_id', 'parent_asset_id', 'location_id'])
                ->keyBy('id');

            $unresolved = [];

            foreach ($pending as $asset_id => $parent_id) {
                $parent = $parents->get($parent_id);

                if (!$parent) {
                    continue;
                }

                if ($parent->customer_id !== null) {
                    $resolved[$asset_id] = [
                        'customer_id' => $parent->customer_id,
                        'location_id' => $parent->location_id,
                    ];
                } elseif ($parent->parent_asset_id !== null) {
                    $unresolved[$asset_id] = $parent->parent_asset_id;
                }
            }

            $pending = $unresolved;
        }

        return $resolved;
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
                    'executing_user_ids' => $ev->executingUsers->pluck('id')->all(),
                ];
            }
        }
        usort($earlier, fn ($a, $b) => strcmp($b['start'], $a['start']));
        $asset->has_past_planned_event = !empty($earlier);
        $asset->earlier_planned_events = $earlier;
    }

    private function applySearchFilter(Builder $query, ?string $search): Builder
    {
        if (!$search) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->whereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%"))
                ->orWhere('serial_number', 'like', "%{$search}%")
                ->orWhereHas('product', function (Builder $p) use ($search) {
                    $p->where('model', 'like', "%{$search}%")
                        ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', "%{$search}%"));
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
        $days = (int) $request->input('days', 60);

        $upcoming_customer_ids = $this->getUpcomingAssetsQuery($days)
            ->whereNotNull('customer_id')
            ->pluck('customer_id');

        $expired_customer_ids = $this->getExpiredAssetsQuery()
            ->whereNotNull('customer_id')
            ->pluck('customer_id');

        $customer_ids = $upcoming_customer_ids->merge($expired_customer_ids)->unique();

        $customers = Customer::whereIn('id', $customer_ids)->with(['assets' => function ($q) {
            $q->select('id', 'customer_id', 'location_id', 'next_service_date', 'status', 'serial_number', 'product_id')
                ->with(['product.productType', 'linkedLocation']);
        }])->get(['id', 'name', 'address', 'postal_code', 'city', 'lat', 'lon']);

        $now = Carbon::now();
        $items = collect();

        foreach ($customers as $c) {
            $eligible = $c->assets->filter(
                fn ($a) => $a->next_service_date &&
                    $a->status !== AssetStatusses::inactive->value
            );

            foreach ($eligible->groupBy('location_id') as $group) {
                $location = $group->first()->linkedLocation;
                $days_min = $group
                    ->map(fn ($a) => $now->diffInDays(Carbon::parse($a->next_service_date), false))
                    ->min();
                $has_expired = $group->contains(fn ($a) => Carbon::parse($a->next_service_date)->lt($now));
                $earliest = $group->sortBy(fn ($a) => $a->next_service_date)->first();

                $items->push([
                    'type' => $location ? 'location' : 'customer',
                    'id' => $location ? $location->id : $c->id,
                    'customer_id' => $c->id,
                    'name' => $location ? ($c->name . ' — ' . $location->title) : $c->name,
                    'address' => $location ? $location->address : $c->address,
                    'postal_code' => $location ? $location->postal_code : $c->postal_code,
                    'city' => $location ? $location->city : $c->city,
                    'lat' => $location ? $location->lat : $c->lat,
                    'lon' => $location ? $location->lon : $c->lon,
                    'has_expired_assets' => $has_expired,
                    'next_service_in_days' => $days_min,
                    'earliest_asset_serial' => $earliest?->serial_number,
                    'earliest_asset_product_type' => $earliest?->product?->productType?->name,
                    'assets' => $group->map(fn ($a) => [
                        'id' => $a->id,
                        'serial_number' => $a->serial_number,
                        'next_service_date' => $a->next_service_date,
                        'status' => $a->status,
                        'product' => ['product_type' => ['name' => $a->product?->productType?->name]],
                    ])->values(),
                ]);
            }
        }

        return inertia('ActivityList/UpcomingActivitiesMap', [
            'items' => $items->values(),
        ]);
    }
}
