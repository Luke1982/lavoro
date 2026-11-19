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

/**
 * One record a webhook said had changed, fetched by Lavoro itself: what the
 * caller sent is never taken for the record. Without an id the whole area is
 * pulled instead.
 */
class PullRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $connection_id, public string $area, public ?string $external_id) {}

    public function handle(Synchronizer $synchronizer): void
    {
        $connection = IntegrationConnection::find($this->connection_id);

        if (!$connection) {
            return;
        }

        if ($this->external_id === null) {
            PullAreaJob::dispatch($connection->id, $this->area, changes_only: true);

            return;
        }

        $area = Area::from($this->area);

        try {
            $outcome = $synchronizer->pullOne($connection, $area, $this->external_id);
        } catch (Refusal $e) {
            if ($e instanceof IntegrationUnavailable) {
                throw $e;
            }

            IntegrationRun::start($connection, $area, IntegrationRun::NOTICE)->fail($e);

            return;
        }

        if (!in_array($outcome, ['unchanged', 'skipped'], true)) {
            IntegrationRun::start($connection, $area, IntegrationRun::NOTICE)
                ->finish([$outcome => 1], 'Na een melding van ' . $connection->label() . ' (' . $this->external_id . ').');
        }
    }
}
