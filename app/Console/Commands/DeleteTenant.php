<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Models\Tenant;
use App\Services\TenantProvisioner;
use Illuminate\Console\Command;

class DeleteTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:delete {id} {--force : Niet vragen}';

    protected $description = 'Verwijdert een tenant: database, MySQL-login, bestanden en centrale rijen';

    public function handle(TenantProvisioner $provisioner): int
    {
        /** Verheft zichzelf tot lavoro_provisioner, of zegt wat je moet typen. */
        if (!$this->runAsProvisioner()) {
            return self::FAILURE;
        }

        $tenant = Tenant::on('central')->find($this->argument('id'));

        if (!$tenant) {
            $this->error('Onbekende tenant.');

            return self::FAILURE;
        }

        $summary = $provisioner->summaryFor($tenant);

        $this->warn("Dit verwijdert {$tenant->name} onherroepelijk:");
        $this->line('  database:   ' . $summary['database']);
        $this->line('  gebruikers: ' . $summary['users']);
        $this->line('  bestanden:  ' . $summary['files']);

        if (!$this->option('force') && !$this->confirm('Doorgaan?', false)) {
            return self::SUCCESS;
        }

        $provisioner->destroy($tenant);

        $this->info("{$tenant->name} is verwijderd.");

        return self::SUCCESS;
    }
}
