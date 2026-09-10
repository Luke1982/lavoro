<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Jobs\ReinstallDemoTenantJob;
use App\Services\Demo\DemoInstaller;
use Illuminate\Console\Command;

/**
 * Installs the demo tenant, or reinstalls it from scratch.
 *
 * Once it exists, the scheduler keeps it fresh every night; this is for the
 * first time, and for a clean slate in between.
 */
class InstallDemoTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'demo:install {--queued : hand it to the provisioning worker instead of waiting}';

    protected $description = 'Installs the demo tenant with a complete set of credible demo data';

    public function handle(DemoInstaller $installer): int
    {
        if ($this->option('queued')) {
            ReinstallDemoTenantJob::dispatch()->onQueue('provisioning');
            $this->info('Queued on the provisioning worker.');

            return self::SUCCESS;
        }

        if (!$this->runAsProvisioner()) {
            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            $tenant = $installer->install();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Demo installed in %.0f seconds: %s', microtime(true) - $started, $tenant->id));
        $this->line('  login:    ' . DemoInstaller::LOGIN);
        $this->line('  password: ' . DemoInstaller::PASSWORD);
        $this->line('  every other demo user logs in with the same password');

        return self::SUCCESS;
    }
}
