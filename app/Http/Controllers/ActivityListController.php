<?php

namespace App\Http\Controllers;

use App\Domain\Search\SearchTerm;
use App\Enums\AssetStatusses;
use App\Http\Requests\ActivityListReadRequest;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\EventType;
use App\Models\Location;
use App\Models\User;
use App\Services\RoutePlanner;
use App\Services\UpcomingAssetTable;
use App\Support\AssetOwnership;
use App\Support\MapDueColors;
use App\Traits\ReadsPerPage;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ActivityListController extends Controller
{
    use ReadsPerPage;

    private const VIEW_SESSION_KEY = 'upcomingactivities.view';

    /**
     * Two views of the same work: one card per customer, or one row per machine. The page
     * opens in whichever one this person picked last.
     */
    public function getUpcomingActivities(ActivityListReadRequest $request, UpcomingAssetTable $table)
    {
        $days = (int) $request->input('days', 60);
        $search = $request->filled('search') ? trim($request->input('search')) : null;
        if ($request->filled('view')) {
            $request->session()->put(self::VIEW_SESSION_KEY, $request->input('view'));
        }

        $view = $request->session()->get(self::VIEW_SESSION_KEY, 'list');
        $content = $view === 'table'
            ? ['table' => $table->build($days, $search, $request->tableQuery(), $this->perPage($request))]
            : [
                'upcomingAssets' => $this->buildCustomerAssetList('upcoming', $days, $search),
                'expiredAssets' => $this->buildCustomerAssetList('expired', $days, $search),
            ];

        return inertia('ActivityList/UpcomingActivities', [
            ...$content,
            'view' => $view,
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

        $ownership = AssetOwnership::resolve($candidates);

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

        $child_ownership = collect(AssetOwnership::resolve(
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

        $like = SearchTerm::like($search);

        return $query->where(fn (Builder $q) => $q
            ->whereHas('customer', fn (Builder $c) => $c->where('name', 'like', $like))
            ->orWhere('serial_number', 'like', $like)
            ->orWhereHas('product', fn (Builder $p) => $p
                ->where('model', 'like', $like)
                ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', $like))));
    }

    private function getUpcomingAssetsQuery(int $days)
    {
        return Asset::upcomingAndUnplanned($days);
    }

    private function getExpiredAssetsQuery()
    {
        return Asset::expired();
    }

    public function map(ActivityListReadRequest $request, RoutePlanner $route_planner)
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
            'dueColors' => MapDueColors::get(),
            'routePlanner' => $request->user()->can('routeplanner.use') ? $route_planner->forMap($request->user()) : null,
        ]);
    }
}
