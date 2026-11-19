<?php

namespace App\Jobs\Integrations;

use App\Domain\Integrations\Area;
use App\Domain\Integrations\IntegrationUnavailable;
use App\Domain\Integrations\Sync\Synchronizer;
use App\Exceptions\Refusal;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends one record that changed here to one linked system. Queued, because a
 * customer is saved in many places and none of them should wait for SnelStart.
 *
 * Only a send that did something is written to the run log: "unchanged" is the
 * answer to most of these jobs (the echo of a change that just came in) and
 * would bury the rest. A failure is always written, the last attempt of a
 * system that is down included.
 */
class PushRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $connection_id, public string $area, public int $record_id) {}

    public function handle(Synchronizer $synchronizer): void
    {
        $connection = IntegrationConnection::find($this->connection_id);
        $area = Area::from($this->area);
        $model = $area->modelClass()::find($this->record_id);

        if (!$connection || !$model) {
            return;
        }

        try {
            $outcome = $synchronizer->push($connection, $area, $model);
        } catch (IntegrationUnavailable $e) {
            if ($this->attempts() >= $this->tries) {
                IntegrationRun::start($connection, $area, IntegrationRun::PUSH, $model)->fail($e);
            }

            throw $e;
        } catch (Throwable $e) {
            IntegrationRun::start($connection, $area, IntegrationRun::PUSH, $model)->fail($e);

            if ($e instanceof Refusal) {
                return;
            }

            throw $e;
        }

        if (!in_array($outcome, ['unchanged', 'skipped'], true)) {
            IntegrationRun::start($connection, $area, IntegrationRun::PUSH, $model)->finish([$outcome => 1]);
        }
    }
}
