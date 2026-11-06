<?php

namespace App\Jobs;

use App\Models\GeneralSetting;
use App\Services\SnelStartClient;
use App\Services\SnelStartImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The nightly import from SnelStart, for one customer. Dispatched for every
 * customer; one without SnelStart connected is done before it starts.
 */
class SyncSnelStartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Tomorrow night is the next try; a second one now would just ask SnelStart again. */
    public int $tries = 1;

    /** Under the queue's retry_after of 90 seconds, or the job is started a second time beside itself. */
    public int $timeout = 80;

    public function handle(): void
    {
        if (!SnelStartClient::isConfigured()) {
            return;
        }

        $import = app(SnelStartImport::class);

        $import->customers(GeneralSetting::get(SnelStartImport::NIGHTLY_UPDATES_CUSTOMERS, '1') === '1');
        $import->materials();
    }
}
