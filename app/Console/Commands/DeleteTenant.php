<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Tenant;
use App\Services\TenantProvisioner;
use Illuminate\Console\Command;

class DeleteTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:delete {id} {--force : Do not ask}';

    protected $description = 'Removes a tenant: database, MySQL login, files and central rows';

    public function handle(TenantProvisioner $provisioner): int
    {
        /** Elevates itself to lavoro_provisioner, or says what to type. */
        if (!$this->runAsProvisioner()) {
            return self::FAILURE;
        }

        $tenant = Tenant::on('central')->find($this->argument('id'));

        if (!$tenant) {
            $this->error('Unknown tenant.');

            return self::FAILURE;
        }

        $summary = $provisioner->summaryFor($tenant);

        $this->warn("This removes {$tenant->name} irrevocably:");
        $this->line('  database: ' . $summary['database']);
        $this->line('  users:    ' . $summary['users']);
        $this->line('  files:    ' . $summary['files']);

        if (!$this->option('force') && !$this->confirm('Continue?', false)) {
            return self::SUCCESS;
        }

        $provisioner->destroy($tenant);

        $this->info("{$tenant->name} has been removed.");

        return self::SUCCESS;
    }
}
