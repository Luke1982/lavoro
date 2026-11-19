<?php

namespace App\Jobs\Integrations;

use App\Domain\Integrations\Sync\Webhooks;
use App\Models\IntegrationConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Hourly, per customer: webhooks that run out within a day are renewed. */
class RenewIntegrationWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(Webhooks $webhooks): void
    {
        foreach (IntegrationConnection::all() as $connection) {
            $webhooks->ensure($connection);
        }
    }
}
