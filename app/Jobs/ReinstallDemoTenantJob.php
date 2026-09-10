<?php

namespace App\Jobs;

use App\Services\Demo\DemoInstaller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The nightly fresh demo. Runs on the provisioning queue: only that worker runs
 * as the account allowed to drop and create a database.
 */
class ReinstallDemoTenantJob implements ShouldQueue
{
    use Queueable;

    /** One attempt: a half-built demo retried gets stuck on "the database already exists". */
    public $tries = 1;

    public $timeout = 900;

    public function __construct()
    {
        $this->onQueue('provisioning');
    }

    public function handle(DemoInstaller $installer): void
    {
        $installer->install();
    }
}
