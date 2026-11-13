<?php

namespace App\Services\TaskChecks;

use App\Models\ServiceOrderTaskInstance;
use App\Models\ServiceOrderTaskInstanceCheck;
use Illuminate\Support\Collection;

/**
 * Reading and writing the check rows of one werkbon task.
 */
class TaskCheckRows
{
    /**
     * Writes a set of checks once for each of the given units (null: for the whole
     * task), numbered from the given place on.
     *
     * @param  Collection<int, CheckItem>  $set
     * @param  array<int, ?int>  $unit_indexes
     */
    public function write(
        ServiceOrderTaskInstance $instance,
        Collection $set,
        array $unit_indexes,
        int $first_position = 1,
    ): void {
        $now = now();

        collect($unit_indexes)
            ->flatMap(fn (?int $unit_index) => $set->values()->map(
                fn (CheckItem $item, int $position) => $item->toRow($instance->id, $unit_index, $first_position + $position, $now),
            ))
            ->chunk(500)
            ->each(fn (Collection $rows) => ServiceOrderTaskInstanceCheck::insert($rows->values()->all()));

        $instance->unsetRelation('checks');
    }

    /**
     * The checks of one unit, or of the whole task, as a set to lay out again.
     *
     * @return Collection<int, CheckItem>
     */
    public function setOf(ServiceOrderTaskInstance $instance, ?int $unit_index): Collection
    {
        return $instance->checks()
            ->where('unit_index', $unit_index)
            ->get()
            ->toBase()
            ->map(fn (ServiceOrderTaskInstanceCheck $check) => CheckItem::fromCopy($check));
    }

    /**
     * The units these checks belong to, lowest first.
     *
     * @param  Collection<int, ServiceOrderTaskInstanceCheck>  $checks
     * @return array<int, int>
     */
    public function unitsOf(Collection $checks): array
    {
        return $checks->pluck('unit_index')->reject(fn ($index) => $index === null)->unique()->sort()->values()->all();
    }
}
