<?php

namespace App\Listeners\Tasks;

use App\Domain\Signals\Tasks\TaskAssetsRegistered;
use App\Services\TaskChecks\TaskCheckUnits;

/**
 * A machine registered on a task that checks per machine is tied to its unit's
 * checks, whoever registered it: the mechanic from the task box, the drawer, or
 * the assistant putting placeholders on a werkbon from a signed offerte.
 */
class LinkTaskChecksToMachines
{
    public function __construct(private readonly TaskCheckUnits $units) {}

    public function handle(TaskAssetsRegistered $signal): void
    {
        $this->units->linkMachines($signal->task_instance, $signal->unit_indexes);
    }
}
