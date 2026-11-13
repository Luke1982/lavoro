<?php

namespace App\Services\TaskChecks;

use App\Models\ServiceOrderTaskInstance;
use App\Services\TaskInstanceBundleService;
use Illuminate\Support\Collection;

/**
 * The machines a task checks per machine: one unit per sold machine, added and
 * removed as the aantal changes, and tied to the machine once it is registered.
 */
class TaskCheckUnits
{
    public function __construct(
        private readonly TaskCheckRows $rows,
        private readonly TaskInstanceBundleService $bundles,
    ) {}

    /**
     * How many sold units a task covers: three airco's are three, two bundles are
     * two. A task without a product covers one.
     */
    public static function count(?int $product_id, ?int $quantity): int
    {
        return $product_id ? max(1, (int) $quantity) : 1;
    }

    /**
     * Where a fresh set of checks goes: once per unit, or once for the whole task.
     *
     * @return array<int, ?int>
     */
    public function freshUnits(ServiceOrderTaskInstance $instance): array
    {
        return $instance->checks_per_unit
            ? range(0, self::count($instance->product_id, $instance->quantity) - 1)
            : [null];
    }

    /**
     * Why the task cannot drop to this many units, naming the machine in the way;
     * null when it can.
     */
    public function blocking(ServiceOrderTaskInstance $instance, int $unit_count, bool $is_bundle): ?string
    {
        $blocking = $this->plannedRemoval($instance, $unit_count, $is_bundle)['blocking'];

        return match (true) {
            $blocking === null => null,
            $blocking['ticked'] => 'Bij machine ' . $blocking['position']
                . ' zijn al checks afgevinkt; kies een hoger aantal.',
            default => 'Machine ' . $blocking['position'] . ' is al geregistreerd; kies een hoger aantal.',
        };
    }

    /**
     * New machines get the same checks as the first, unticked; machines that drop
     * off take their checks along, and the rest are numbered from the start again,
     * so a unit's number is always its place — the place a bundle sits at too.
     */
    public function sync(ServiceOrderTaskInstance $instance): void
    {
        if (!$instance->checks_per_unit) {
            return;
        }

        $units = $this->rows->unitsOf($instance->checks()->get());
        $wanted = self::count($instance->product_id, $instance->quantity);

        if (count($units) < $wanted) {
            $next = $units === [] ? 0 : max($units) + 1;
            $added = range($next, $next + $wanted - count($units) - 1);

            $this->rows->write($instance, $this->rows->setOf($instance, $units[0] ?? null), $added);

            return;
        }

        $remove = $this->plannedRemoval($instance, $wanted, (bool) $instance->loadMissing('product')->product?->bundle)['remove'];

        if ($remove === []) {
            return;
        }

        $instance->checks()->whereIn('unit_index', $remove)->delete();

        foreach (array_values(array_diff($units, $remove)) as $position => $unit_index) {
            if ($unit_index !== $position) {
                $instance->checks()->where('unit_index', $unit_index)->update(['unit_index' => $position]);
            }
        }
    }

    /**
     * Lays the task's own checks out again for the other mode. Only while nothing is
     * ticked, which the edit request makes sure of.
     */
    public function relayout(ServiceOrderTaskInstance $instance): void
    {
        $first = $instance->checks()->first();

        if (!$first) {
            return;
        }

        $set = $this->rows->setOf($instance, $first->unit_index);
        $instance->checks()->delete();

        $this->rows->write($instance, $set, $this->freshUnits($instance));
    }

    /**
     * Ties each unit's checks to its machine, so correcting a serial or replacing a
     * machine never moves ticks to another one.
     *
     * A bundle's unit is the bundle at the same place. For a plain product, the
     * machine entered in a unit's own box goes to that unit; any other machine of the
     * task takes the first unit without one, oldest machine first.
     *
     * @param  array<int, int>  $unit_indexes  Machine id to the unit it was entered for.
     */
    public function linkMachines(ServiceOrderTaskInstance $instance, array $unit_indexes = []): void
    {
        if (!$instance->checks_per_unit) {
            return;
        }

        $fresh = ServiceOrderTaskInstance::with(['product', 'assets'])->findOrFail($instance->id);

        $machine_of = $fresh->checks()->whereNotNull('unit_index')->get()->groupBy('unit_index')
            ->map(fn (Collection $unit) => $unit->firstWhere('asset_id', '!==', null)?->asset_id)
            ->all();

        $wanted = $fresh->product?->bundle
            ? $this->bundles->containers($fresh)->pluck('id')
                ->only(array_keys(array_filter($machine_of, fn (?int $asset_id) => $asset_id === null)))
                ->all()
            : $this->machinesForFreeUnits($fresh, $machine_of, $unit_indexes);

        foreach ($wanted as $unit_index => $asset_id) {
            $instance->checks()->where('unit_index', $unit_index)->update(['asset_id' => $asset_id]);
        }

        $instance->unsetRelation('checks');
    }

    /**
     * For a plain product: which machine each unit without one gets. A machine
     * entered in a unit's own box goes there; the other machines of the task fill
     * the remaining units, oldest machine first.
     *
     * @param  array<int, ?int>  $machine_of  Unit to the machine its checks are tied to.
     * @param  array<int, int>  $unit_indexes  Machine id to the unit it was entered for.
     * @return array<int, int> Unit to machine.
     */
    private function machinesForFreeUnits(ServiceOrderTaskInstance $instance, array $machine_of, array $unit_indexes): array
    {
        $taken = array_filter($machine_of);
        $wanted = [];

        foreach ($unit_indexes as $asset_id => $unit_index) {
            if (array_key_exists($unit_index, $machine_of) && $machine_of[$unit_index] === null
                && !in_array($asset_id, $taken, true)) {
                $wanted[$unit_index] = $asset_id;
                $taken[] = $asset_id;
            }
        }

        $loose = $instance->assets->whereNull('parent_asset_id')->sortBy('id')->pluck('id')
            ->reject(fn (int $id) => in_array($id, $taken, true))
            ->values();

        $free = array_diff(array_keys($machine_of), array_keys(array_filter($machine_of)), array_keys($wanted));

        foreach (array_values($free) as $position => $unit_index) {
            if (!isset($loose[$position])) {
                break;
            }

            $wanted[$unit_index] = $loose[$position];
        }

        return $wanted;
    }

    /**
     * Which units go when the task drops to this many, or which one is in the way.
     *
     * A plain product's unit is its own machine, so any free unit can go, highest
     * first; one with a check ticked or a machine registered stays. A bundle's unit
     * is the bundle at the same place, and bundles only come off the end, so the
     * last units go or none do. Whether a bundle still holds parts is the aantal
     * check's to say, not this one's.
     *
     * @return array{remove: array<int, int>, blocking: ?array{position: int, ticked: bool}}
     */
    private function plannedRemoval(ServiceOrderTaskInstance $instance, int $unit_count, bool $is_bundle): array
    {
        $checks = $instance->checks()->get();
        $units = $this->rows->unitsOf($checks);
        $excess = count($units) - $unit_count;

        if (!$instance->checks_per_unit || $excess <= 0) {
            return ['remove' => [], 'blocking' => null];
        }

        $ticked = $this->rows->unitsOf($checks->where('is_done', true));
        $blocked = fn (int $unit_index, bool $is_ticked) => ['remove' => [], 'blocking' => [
            'position' => array_search($unit_index, $units, true) + 1,
            'ticked' => $is_ticked,
        ]];

        if ($is_bundle) {
            $going = array_slice($units, -$excess);
            $stuck = array_intersect($going, $ticked);

            return $stuck === [] ? ['remove' => $going, 'blocking' => null] : $blocked(max($stuck), true);
        }

        $busy = array_unique(array_merge($ticked, $this->rows->unitsOf($checks->whereNotNull('asset_id'))));
        $free = array_values(array_diff(array_reverse($units), $busy));

        if (count($free) >= $excess) {
            return ['remove' => array_slice($free, 0, $excess), 'blocking' => null];
        }

        return $blocked(max($busy), in_array(max($busy), $ticked, true));
    }
}
