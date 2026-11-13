<?php

namespace App\Services\TaskChecks;

use App\Models\ServiceOrderTaskInstance;

/**
 * The checks on a werkbon task: copied from its template when the task is made,
 * and kept in line with the task when it is edited.
 *
 * A task carries copies, never references: a checklist edited later must not
 * change a werkbon a mechanic is working on. So anything laid out again after
 * creation is laid out from the task's own checks, not from the library.
 */
class TaskInstanceChecks
{
    public function __construct(
        private readonly TaskCheckCatalogue $catalogue,
        private readonly TaskCheckRows $rows,
        private readonly TaskCheckUnits $units,
        private readonly TaskCheckExtras $extras,
    ) {}

    /** The checks of a task that was just made: its template's, then the extras it does not already have. */
    public function seed(ServiceOrderTaskInstance $instance, PickedExtras $extras): void
    {
        $instance->loadMissing('serviceOrderTask');

        $set = $this->catalogue->forTemplate($instance->serviceOrderTask);

        $this->rows->write($instance, $set->concat($this->extras->beyond($set, $extras)), $this->units->freshUnits($instance));
    }

    /**
     * Follows an edit of the task: lays the checks out again after a switch between
     * per machine and the whole task, or adds and removes units for a new aantal;
     * then makes the extras what was picked (null: left alone), and ties machines to
     * units.
     */
    public function followEdit(ServiceOrderTaskInstance $instance, bool $was_per_unit, ?PickedExtras $extras): void
    {
        if ((bool) $instance->checks_per_unit !== $was_per_unit) {
            $this->units->relayout($instance);
        } else {
            $this->units->sync($instance);
        }

        if ($extras !== null) {
            $this->extras->sync($instance, $extras, $this->units->freshUnits($instance));
        }

        $this->units->linkMachines($instance);
    }

    public function hasTicks(ServiceOrderTaskInstance $instance): bool
    {
        return $instance->checks()->where('is_done', true)->exists();
    }
}
