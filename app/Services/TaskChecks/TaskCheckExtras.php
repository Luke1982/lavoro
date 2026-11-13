<?php

namespace App\Services\TaskChecks;

use App\Models\ServiceOrderTaskInstance;
use App\Models\ServiceOrderTaskInstanceCheck;
use Illuminate\Support\Collection;

/**
 * The checks picked on top of a task's template. A checklist picked once stays as
 * it was copied: saving the task again never expands it from the library again,
 * which may have changed since.
 */
class TaskCheckExtras
{
    public function __construct(
        private readonly TaskCheckCatalogue $catalogue,
        private readonly TaskCheckRows $rows,
    ) {}

    /**
     * The picked extras minus the library checks the task already carries.
     *
     * @param  Collection<int, CheckItem>  $carried
     * @return Collection<int, CheckItem>
     */
    public function beyond(Collection $carried, PickedExtras $extras): Collection
    {
        $carried_ids = $carried->pluck('check_id')->filter()->all();

        return $this->catalogue->picked($extras)
            ->reject(fn (CheckItem $item) => $item->check_id && in_array($item->check_id, $carried_ids, true))
            ->values();
    }

    /**
     * Makes the extras on the task what was picked: extras no longer wanted go from
     * every unit, new ones are added to every unit after the existing checks.
     */
    public function sync(ServiceOrderTaskInstance $instance, PickedExtras $extras, array $fresh_units): void
    {
        [$wanted, $current] = $this->delta($instance, $extras);

        $gone = $current->keys()->diff($wanted->keys())->all();
        $instance->checks()->whereIn('id', $current->only($gone)->flatten()->all())->delete();

        $new = $wanted->except($current->keys()->all())->values();

        if ($new->isEmpty()) {
            return;
        }

        $units = $this->rows->unitsOf($instance->checks()->get());
        $indexes = $instance->checks_per_unit ? ($units ?: $fresh_units) : [null];

        $this->rows->write($instance, $new, $indexes, (int) $instance->checks()->max('sort_order') + 1);
    }

    /** Whether saving these extras would take away one that is already ticked. */
    public function dropsTicked(ServiceOrderTaskInstance $instance, PickedExtras $extras): bool
    {
        [$wanted, $current] = $this->delta($instance, $extras);

        $gone = $current->except($wanted->keys()->all())->flatten()->all();

        return $instance->checks()->whereIn('id', $gone)->where('is_done', true)->exists();
    }

    /** Whether saving these extras changes anything. */
    public function wouldChange(ServiceOrderTaskInstance $instance, PickedExtras $extras): bool
    {
        [$wanted, $current] = $this->delta($instance, $extras);

        return $wanted->keys()->sort()->values()->all() !== $current->keys()->sort()->values()->all();
    }

    /**
     * The extras the task should carry after this save, and those it carries now with
     * the ids of their copies, both keyed by CheckItem::key().
     *
     * @return array{0: Collection<string, CheckItem>, 1: Collection<string, Collection<int, int>>}
     */
    private function delta(ServiceOrderTaskInstance $instance, PickedExtras $extras): array
    {
        $checks = $instance->checks()->get()->toBase();
        $extra_rows = $checks->where('is_extra', true);

        $kept = $extra_rows
            ->filter(fn ($check) => in_array($check->service_order_task_checklist_id, $extras->checklist_ids, true))
            ->map(fn ($check) => CheckItem::fromCopy($check));

        $present_lists = $extra_rows->pluck('service_order_task_checklist_id')->filter()->all();
        $carried = $checks->where('is_extra', false)->map(fn ($check) => CheckItem::fromCopy($check))->concat($kept);

        $picked = $this->beyond($carried, new PickedExtras(
            $extras->check_ids,
            array_values(array_diff($extras->checklist_ids, $present_lists)),
            $extras->titles,
        ));

        return [
            $kept->concat($picked)->keyBy(fn (CheckItem $item) => $item->key()),
            $extra_rows->groupBy(fn (ServiceOrderTaskInstanceCheck $check) => CheckItem::fromCopy($check)->key())
                ->map->pluck('id'),
        ];
    }
}
