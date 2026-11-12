<?php

namespace App\Services;

use App\Enums\AssetStatusses;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Support\AssetOwnership;
use App\Support\MapDueColors;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The upcoming activities as one table: a row per machine that is overdue or due within
 * the window, searchable, filterable, sortable and paged.
 *
 * A child machine is owned through its parent chain, which SQL cannot follow, so every
 * candidate is fetched lean, resolved to its owner and filtered and sorted here. Only the
 * page on screen gets its photo, storingen and werkbonnen loaded.
 */
class UpcomingAssetTable
{
    public const SORTS = ['date', 'serial', 'machine', 'customer', 'location'];

    public const DIRECTIONS = ['asc', 'desc'];

    public const PLANNING = ['unplanned', 'planned', 'all'];

    public const TICKETS = ['with', 'without'];

    private const SUMMARY = ['id', 'machine', 'serial_number', 'next_service_date', 'due'];

    private const SEARCHABLE = ['serial_number', 'machine', 'product_type', 'customer', 'location'];

    private const SORT_KEYS = [
        'date' => 'next_service_date',
        'serial' => 'serial_number',
        'machine' => 'machine',
        'customer' => 'customer',
        'location' => 'location',
    ];

    /**
     * @param  array{
     *     sort: string, direction: string, planning: string,
     *     customer: ?int, type: ?int, due: ?string, tickets: ?string,
     * }  $query
     */
    public function build(int $days, ?string $search, array $query, int $per_page): array
    {
        $due_groups = $this->dueGroups();
        $rows = $this->search($this->candidates($days, $query['planning'], $due_groups), $search);
        $listed = $this->sort($this->filter($rows, $query), $query);
        $page = $this->paginate($listed, $per_page);

        return [
            'rows' => $page,
            'customerMachines' => $this->customerMachines($listed, $page->getCollection()->pluck('customer_id')),
            'filters' => $this->filterOptions($rows, $due_groups),
            'query' => $query,
        ];
    }

    private function candidates(int $days, string $planning, array $due_groups): Collection
    {
        $assets = Asset::query()
            ->select([
                'assets.id',
                'assets.customer_id',
                'assets.parent_asset_id',
                'assets.location_id',
                'assets.serial_number',
                'assets.next_service_date',
                'brands.name as brand_name',
                'products.model',
                'product_types.id as product_type_id',
                'product_types.name as product_type',
            ])
            ->withCount('plannableTickets')
            ->leftJoin('products', 'products.id', '=', 'assets.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->leftJoin('product_types', 'product_types.id', '=', 'products.product_type_id')
            ->where('assets.status', '!=', AssetStatusses::inactive->value)
            ->where('assets.next_service_date', '<=', now()->addDays($days))
            ->when($planning === 'planned', fn (Builder $q) => $q->planned())
            ->when($planning === 'unplanned', fn (Builder $q) => $q->unplanned())
            ->toBase()
            ->get();

        $ownership = AssetOwnership::resolve($assets);
        $customers = Customer::whereIn('id', array_column($ownership, 'customer_id'))->pluck('name', 'id');
        $locations = Location::whereIn('id', array_filter(array_column($ownership, 'location_id')))
            ->pluck('title', 'id');

        return $assets
            ->filter(fn (object $asset) => isset($ownership[$asset->id])
                && $customers->has($ownership[$asset->id]['customer_id']))
            ->map(function (object $asset) use ($ownership, $customers, $locations, $due_groups) {
                ['customer_id' => $customer_id, 'location_id' => $location_id] = $ownership[$asset->id];

                return [
                    'id' => $asset->id,
                    'serial_number' => $asset->serial_number,
                    'machine' => trim($asset->brand_name . ' ' . $asset->model),
                    'product_type_id' => $asset->product_type_id,
                    'product_type' => $asset->product_type,
                    'customer_id' => $customer_id,
                    'customer' => $customers[$customer_id],
                    'location' => $locations[$location_id] ?? null,
                    'next_service_date' => $asset->next_service_date,
                    'due' => $this->dueOf($asset->next_service_date, $due_groups),
                    'plannable_tickets_count' => $asset->plannable_tickets_count,
                ];
            })
            ->values();
    }

    /**
     * The same groups, colours and boundaries as the pins on the map, so a machine is
     * "binnen 30 dagen" in the table exactly when its pin says so.
     *
     * @return array<int, array{id: string, name: string, color: string, days?: int}>
     */
    private function dueGroups(): array
    {
        $colors = MapDueColors::get();

        return [
            ['id' => 'expired', 'name' => 'Verlopen', 'color' => $colors['expired']],
            ...array_map(fn (array $band) => [
                'id' => 'within-' . $band['days'],
                'name' => 'Binnen ' . $band['days'] . ' ' . ($band['days'] === 1 ? 'dag' : 'dagen'),
                'color' => $band['color'],
                'days' => $band['days'],
            ], $colors['bands']),
            ['id' => 'later', 'name' => 'Later', 'color' => $colors['later']],
        ];
    }

    private function dueOf(string $next_service_date, array $due_groups): string
    {
        $days_until = now()->diffInDays(Carbon::parse($next_service_date), false);

        if ($days_until < 0) {
            return 'expired';
        }

        foreach ($due_groups as $group) {
            if (isset($group['days']) && $days_until <= $group['days']) {
                return $group['id'];
            }
        }

        return 'later';
    }

    /**
     * Matched here rather than in SQL, so a part is found by the name of the customer who
     * owns the machine it sits in.
     */
    private function search(Collection $rows, ?string $search): Collection
    {
        if ($search === null) {
            return $rows;
        }

        return $rows->filter(fn (array $row) => Str::contains(
            implode(' ', Arr::only($row, self::SEARCHABLE)),
            $search,
            ignoreCase: true,
        ));
    }

    private function filter(Collection $rows, array $query): Collection
    {
        return $rows
            ->when($query['customer'], fn (Collection $rows, int $id) => $rows->where('customer_id', $id))
            ->when($query['type'], fn (Collection $rows, int $id) => $rows->where('product_type_id', $id))
            ->when($query['due'], fn (Collection $rows, string $due) => $rows->where('due', $due))
            ->when($query['tickets'], fn (Collection $rows, string $tickets) => $rows->filter(
                fn (array $row) => ($row['plannable_tickets_count'] > 0) === ($tickets === 'with'),
            ));
    }

    /**
     * Ties fall back to the due date, so a sorted column still reads soonest-first within
     * each customer, location or machine.
     */
    private function sort(Collection $rows, array $query): Collection
    {
        $key = self::SORT_KEYS[$query['sort']];
        $sign = $query['direction'] === 'desc' ? -1 : 1;

        return $rows->sort(fn (array $a, array $b) => $sign * strnatcasecmp((string) $a[$key], (string) $b[$key])
            ?: strcmp($a['next_service_date'], $b['next_service_date'])
            ?: $a['id'] <=> $b['id'])->values();
    }

    /**
     * Each filter offers the customers, types and due groups among the machines in the
     * window before any filter narrows it, so a filter can always be switched to another
     * value without first being cleared.
     */
    private function filterOptions(Collection $rows, array $due_groups): array
    {
        $options = fn (string $id, string $name) => $rows
            ->whereNotNull($id)
            ->unique($id)
            ->map(fn (array $row) => ['id' => $row[$id], 'name' => $row[$name]])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            'customers' => $options('customer_id', 'customer'),
            'productTypes' => $options('product_type_id', 'product_type'),
            'due' => collect($due_groups)->whereIn('id', $rows->pluck('due')->unique())->values(),
        ];
    }

    private function paginate(Collection $rows, int $per_page): LengthAwarePaginator
    {
        $last_page = max(1, (int) ceil($rows->count() / $per_page));
        $page = min(LengthAwarePaginator::resolveCurrentPage(), $last_page);

        return (new LengthAwarePaginator(
            $this->withDetails($rows->forPage($page, $per_page)->values()),
            $rows->count(),
            $per_page,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        ))->withQueryString();
    }

    private function withDetails(Collection $rows): Collection
    {
        $assets = Asset::with([
            'product.mainImage',
            'parentAsset:id,serial_number',
            'plannableTickets',
            'pendingServiceJobs.serviceOrder.comingEvents',
        ])->findMany($rows->pluck('id'))->keyBy('id');

        return $rows->map(function (array $row) use ($assets) {
            $asset = $assets[$row['id']];

            return [
                ...$row,
                'image_id' => $asset->product?->mainImage->first()?->id,
                'parent' => $asset->parentAsset?->only(['id', 'serial_number']),
                'tickets' => $asset->plannableTickets
                    ->map->only(['id', 'subject', 'description', 'status', 'status_code', 'created_at'])
                    ->values(),
                'service_orders' => $asset->pendingServiceJobs
                    ->map(fn (ServiceJob $job) => $job->serviceOrder)
                    ->filter()
                    ->unique('id')
                    ->map(fn (ServiceOrder $order) => [
                        'id' => $order->id,
                        'planned_on' => $order->comingEvents->first()?->start,
                    ])
                    ->values(),
            ];
        });
    }

    /**
     * Every machine in the list of each customer on this page, wherever in the list it
     * sits, so ticking one machine can offer the rest of that customer's work. Sent once
     * per customer rather than on every row: a customer with a hundred machines due would
     * otherwise repeat them on each of their rows.
     */
    private function customerMachines(Collection $listed, Collection $customer_ids): Collection
    {
        return $listed
            ->whereIn('customer_id', $customer_ids)
            ->groupBy('customer_id')
            ->map(fn (Collection $machines) => $machines
                ->map(fn (array $row) => Arr::only($row, self::SUMMARY))
                ->values());
    }
}
