<?php

namespace App\Domain\Planning;

use App\Enums\EventStatusses;
use App\Enums\ServiceOrderTypes;
use App\Models\Asset;
use App\Models\Event;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What each mechanic has done before with this kind of machine.
 *
 * Three records say so, and they say different things. A task completed with
 * the product on it is an installation. A closed storing, or a machine signed
 * off on a werkbon, is service. A finished werkbon says only that somebody was
 * there. Which counts most depends on the job: the mechanic who has hung forty
 * aircos is the one to send to hang the forty-first, not necessarily the one who
 * has repaired the most of them.
 *
 * "This kind" is the same product type or the same brand. Having installed
 * aircos of another make still makes somebody an airco installer; having worked
 * on this very make counts double.
 *
 * Only as good as what gets recorded. Where nobody ticks off tasks or closes
 * storingen under their own name, every count is nought and the ranking falls
 * back to name — which is the right way for it to degrade.
 */
final class TechnicianExperience
{
    /** @var array<string, array{installed: int, service: int, finished_orders: int}> */
    private const WEIGHTS = [
        'installation' => ['installed' => 3, 'service' => 1, 'finished_orders' => 2],
        'service' => ['installed' => 1, 'service' => 3, 'finished_orders' => 2],
        'mixed' => ['installed' => 1, 'service' => 1, 'finished_orders' => 1],
    ];

    /**
     * Per mechanic: how many installations, how much service and how many
     * finished werkbonnen involved these products' kind of machine, and the
     * score that weighs them for this kind of job.
     *
     * @param  array<int, int>  $product_ids
     * @return array<int, array{installed: int, service: int, finished_orders: int, score: int}>
     */
    public function of(array $product_ids, ?int $asset_id, ServiceOrderTypes $job_type): array
    {
        ['brand_ids' => $brand_ids, 'type_ids' => $type_ids] = $this->kindsOf($product_ids, $asset_id);

        if ($brand_ids === [] && $type_ids === []) {
            return [];
        }

        $tallies = [
            'installed' => $this->installations($brand_ids, $type_ids),
            'service' => $this->sumOf(
                $this->repairs($brand_ids, $type_ids),
                $this->serviceJobs($brand_ids, $type_ids),
            ),
            'finished_orders' => $this->finishedOrders($brand_ids, $type_ids),
        ];

        $weights = self::WEIGHTS[$job_type->value];
        $experience = [];

        foreach ($tallies as $source => $per_user) {
            foreach ($per_user as $user_id => $tally) {
                $experience[$user_id] ??= ['installed' => 0, 'service' => 0, 'finished_orders' => 0, 'score' => 0];
                $experience[$user_id][$source] += $tally['total'];
                $experience[$user_id]['score'] += $weights[$source] * ($tally['total'] + $tally['same_brand']);
            }
        }

        return $experience;
    }

    /**
     * The brands and product types of these products and this machine.
     *
     * @param  array<int, int>  $product_ids
     * @return array{brand_ids: array<int, int>, type_ids: array<int, int>}
     */
    private function kindsOf(array $product_ids, ?int $asset_id): array
    {
        if ($asset_id !== null) {
            $product_ids[] = Asset::whereKey($asset_id)->value('product_id');
        }

        $products = Product::query()
            ->whereIn('id', array_unique(array_filter($product_ids)))
            ->get(['brand_id', 'product_type_id']);

        $ids = fn (string $column) => $products->pluck($column)->filter()->unique()
            ->map(fn ($id) => (int) $id)->values()->all();

        return ['brand_ids' => $ids('brand_id'), 'type_ids' => $ids('product_type_id')];
    }

    /**
     * Tasks somebody ticked off with a product of this kind on them. Cancelled
     * ones were never done, so they say nothing about who can do it.
     *
     * @return array<int, array{total: int, same_brand: int}>
     */
    private function installations(array $brand_ids, array $type_ids): array
    {
        $query = ServiceOrderTaskInstance::query()
            ->join('products', 'products.id', '=', 'service_order_task_instances.product_id')
            ->where('service_order_task_instances.is_complete', true)
            ->where('service_order_task_instances.is_cancelled', false)
            ->whereNotNull('service_order_task_instances.completed_by');

        return $this->tally($query, 'service_order_task_instances.completed_by', $brand_ids, $type_ids);
    }

    /** @return array<int, array{total: int, same_brand: int}> */
    private function repairs(array $brand_ids, array $type_ids): array
    {
        $query = Ticket::query()
            ->join('assets', 'assets.id', '=', 'tickets.asset_id')
            ->join('products', 'products.id', '=', 'assets.product_id')
            ->whereNotNull('tickets.closed_by_id');

        return $this->tally($query, 'tickets.closed_by_id', $brand_ids, $type_ids);
    }

    /**
     * Machines signed off on a werkbon — the onderhoud and keuringen, which is
     * service work just as much as a storing is.
     *
     * @return array<int, array{total: int, same_brand: int}>
     */
    private function serviceJobs(array $brand_ids, array $type_ids): array
    {
        $query = ServiceJob::query()
            ->join('assets', 'assets.id', '=', 'service_jobs.asset_id')
            ->join('products', 'products.id', '=', 'assets.product_id')
            ->whereNotNull('service_jobs.completed_by');

        return $this->tally($query, 'service_jobs.completed_by', $brand_ids, $type_ids);
    }

    /**
     * Closed werkbonnen that had this kind of machine on them, counted once per
     * werkbon for everyone who worked it — named on the werkbon itself or on one
     * of its appointments that went ahead.
     *
     * @return array<int, array{total: int, same_brand: int}>
     */
    private function finishedOrders(array $brand_ids, array $type_ids): array
    {
        $any = $this->ordersWith($brand_ids, $type_ids);
        $same_brand = $brand_ids === [] ? collect() : $this->ordersWith($brand_ids, [])->flip();

        $tallies = [];

        foreach ($this->workersOn($any) as $pair) {
            $tallies[$pair->user_id] ??= ['total' => 0, 'same_brand' => 0];
            $tallies[$pair->user_id]['total']++;

            if ($same_brand->has($pair->order_id)) {
                $tallies[$pair->user_id]['same_brand']++;
            }
        }

        return $tallies;
    }

    /** @return Collection<int, int> */
    private function ordersWith(array $brand_ids, array $type_ids): Collection
    {
        $of_this_kind = fn (Builder $query) => $this->matching($query, $brand_ids, $type_ids);

        return ServiceOrder::query()
            ->whereHas('serviceOrderStage', fn ($query) => $query->closesOrder())
            ->where(fn ($query) => $query
                ->whereHas('taskInstances.product', $of_this_kind)
                ->orWhereHas('serviceJobs.asset.product', $of_this_kind))
            ->pluck('service_orders.id');
    }

    /**
     * Who worked which of these werkbonnen, each pair once.
     *
     * @param  Collection<int, int>  $order_ids
     * @return Collection<int, object{user_id: int, order_id: int}>
     */
    private function workersOn(Collection $order_ids): Collection
    {
        if ($order_ids->isEmpty()) {
            return collect();
        }

        $order_type = (new ServiceOrder)->getMorphClass();

        $direct = DB::table('userables')
            ->where('userable_type', $order_type)
            ->where('type', 'executing')
            ->whereIn('userable_id', $order_ids)
            ->select('user_id', 'userable_id as order_id');

        $through_appointments = DB::table('eventables')
            ->join('events', 'events.id', '=', 'eventables.event_id')
            ->join('userables', fn ($join) => $join
                ->on('userables.userable_id', '=', 'events.id')
                ->where('userables.userable_type', (new Event)->getMorphClass())
                ->where('userables.type', 'executing'))
            ->where('eventables.eventable_type', $order_type)
            ->whereIn('eventables.eventable_id', $order_ids)
            ->where('events.status', '!=', EventStatusses::cancelled->value)
            ->whereNull('events.deleted_at')
            ->select('userables.user_id', 'eventables.eventable_id as order_id');

        return $direct->union($through_appointments)->get();
    }

    /**
     * Counts per person, with how many of those were this very brand.
     *
     * @return array<int, array{total: int, same_brand: int}>
     */
    private function tally(Builder $query, string $person_column, array $brand_ids, array $type_ids): array
    {
        $this->matching($query, $brand_ids, $type_ids);

        $same_brand = $brand_ids === []
            ? '0'
            : 'SUM(CASE WHEN products.brand_id IN (' . implode(', ', array_fill(0, count($brand_ids), '?'))
                . ') THEN 1 ELSE 0 END)';

        return $query
            ->groupBy($person_column)
            ->selectRaw(
                $person_column . ' as user_id, COUNT(*) as total, ' . $same_brand . ' as same_brand',
                $brand_ids,
            )
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->user_id => [
                'total' => (int) $row->total,
                'same_brand' => (int) $row->same_brand,
            ]])
            ->all();
    }

    /**
     * @param  array<int, int>  $brand_ids
     * @param  array<int, int>  $type_ids
     */
    private function matching(Builder $query, array $brand_ids, array $type_ids): void
    {
        $query->where(function (Builder $where) use ($brand_ids, $type_ids) {
            if ($brand_ids !== []) {
                $where->whereIn('products.brand_id', $brand_ids);
            }

            if ($type_ids !== []) {
                $where->orWhereIn('products.product_type_id', $type_ids);
            }
        });
    }

    /**
     * @param  array<int, array{total: int, same_brand: int}>  ...$tallies
     * @return array<int, array{total: int, same_brand: int}>
     */
    private function sumOf(array ...$tallies): array
    {
        $sum = [];

        foreach ($tallies as $per_user) {
            foreach ($per_user as $user_id => $tally) {
                $sum[$user_id] ??= ['total' => 0, 'same_brand' => 0];
                $sum[$user_id]['total'] += $tally['total'];
                $sum[$user_id]['same_brand'] += $tally['same_brand'];
            }
        }

        return $sum;
    }
}
