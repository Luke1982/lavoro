<?php

namespace App\Jobs\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\Sync\Synchronizer;
use App\Exceptions\Refusal;
use App\Models\IntegrationConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One scheduled pull of one area. A refusal (wrong keys, a missing setting) is
 * already in the run log and on the settings screen, and trying again would
 * only get the same answer; a system that is briefly unreachable is tried
 * again later.
 */
class PullAreaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Under the queue's retry_after of 90 seconds, or the job is started a second time beside itself. */
    public int $timeout = 80;

    public function __construct(public int $connection_id, public string $area, public bool $changes_only = false) {}

    public function handle(Synchronizer $synchronizer): void
    {
        $connection = IntegrationConnection::find($this->connection_id);

        if (!$connection) {
            return;
        }

        try {
            $synchronizer->pull($connection, Area::from($this->area), $this->changes_only);
        } catch (IntegrationUnavailable) {
            $this->release(600);
        } catch (Refusal) {
            return;
        }
    }
}
