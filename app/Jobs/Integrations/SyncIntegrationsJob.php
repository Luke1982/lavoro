<?php

namespace App\Jobs\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\ChangeDetection;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The scheduled pulls of one customer. Dispatched for every customer; one
 * without connections is done before it starts.
 *
 * Nightly every area a connection reads is compared in full: the only way to
 * notice what was removed there, and the safety net under webhooks. The
 * frequent run, every quarter of an hour, only asks the systems that can say
 * what changed since last time: for the others it would be a full comparison
 * every quarter.
 *
 * Each pull is a job of its own, so one system that is down does not hold up
 * the next.
 */
class SyncIntegrationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How long the run log keeps its rows. */
    public const KEEP_RUNS_DAYS = 90;

    public int $tries = 1;

    public function __construct(public bool $frequent = false) {}

    public function handle(): void
    {
        foreach (IntegrationConnection::active()->get() as $connection) {
            foreach (Area::synced() as $area) {
                if (!$connection->reads($area)) {
                    continue;
                }

                if ($this->frequent && $connection->connectorClass()::changeDetection($area) !== ChangeDetection::Delta) {
                    continue;
                }

                PullAreaJob::dispatch($connection->id, $area->value, changes_only: $this->frequent);
            }
        }

        if (!$this->frequent) {
            IntegrationRun::olderThan(now()->subDays(self::KEEP_RUNS_DAYS))->delete();
        }
    }
}
