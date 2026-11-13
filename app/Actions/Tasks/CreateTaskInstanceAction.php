<?php

namespace App\Actions\Tasks;

use App\Models\ServiceOrderTaskInstance;
use App\Services\TaskChecks\PickedExtras;
use App\Services\TaskChecks\TaskInstanceChecks;
use Illuminate\Support\Facades\DB;

/**
 * Puts a task on a werkbon together with the checks its template hands it.
 *
 * The task form and the assistant both create tasks here, so a task never
 * reaches a werkbon without the checks that make it complete.
 */
class CreateTaskInstanceAction
{
    public function __construct(private readonly TaskInstanceChecks $checks) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes, ?PickedExtras $extras = null): ServiceOrderTaskInstance
    {
        return DB::transaction(function () use ($attributes, $extras) {
            $instance = ServiceOrderTaskInstance::create($attributes);
            $this->checks->seed($instance, $extras ?? new PickedExtras);

            return $instance;
        });
    }
}
