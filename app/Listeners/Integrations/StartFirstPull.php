<?php

namespace App\Listeners\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\Sync\Synchronizer;
use App\Domain\Signals\ModelChanged;
use App\Jobs\Integrations\PullAreaJob;
use App\Models\IntegrationConnection;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * A connection that starts reading an area is pulled in full straight away,
 * not at 03:00. Until then nothing new is sent there (see Synchronizer::send):
 * a customer that is already there has to be recognised first, or it is made
 * twice.
 */
class StartFirstPull implements ShouldHandleEventsAfterCommit
{
    public function __construct(private Synchronizer $synchronizer) {}

    public function handle(ModelChanged $signal): void
    {
        $connection = $signal->model;

        if (!$connection instanceof IntegrationConnection || !in_array($signal->action, ['created', 'updated'], true)) {
            return;
        }

        foreach (Area::synced() as $area) {
            if ($connection->reads($area) && !$this->synchronizer->pulledInFull($connection, $area)) {
                PullAreaJob::dispatch($connection->id, $area->value);
            }
        }
    }
}
