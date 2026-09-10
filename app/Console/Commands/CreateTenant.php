<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsProvisioner;
use App\Services\TenantProvisioner;
use Illuminate\Console\Command;

class CreateTenant extends Command
{
    use RunsAsProvisioner;

    protected $signature = 'tenant:create
        {name : De bedrijfsnaam}
        {email : Het e-mailadres van de eerste beheerder}
        {--admin-password= : Wachtwoord; leeg laten genereert er een}
        {--package=starter}
        {--modules= : Kommagescheiden}';

    protected $description = 'Creates a new tenant with its own database, MySQL login and admin';

    public function handle(TenantProvisioner $provisioner): int
    {
        /**
         * This first: it starts this command again as lavoro_provisioner when
         * that is allowed without a password. If it cannot, it says what to
         * type instead of breaking on the database halfway through.
         */
        if (!$this->runAsProvisioner()) {
            return self::FAILURE;
        }

        try {
            ['tenant' => $tenant, 'password' => $password] = $provisioner->create(
                name: $this->argument('name'),
                email: $this->argument('email'),
                password: (string) $this->option('admin-password'),
                package: (string) $this->option('package'),
                modules: explode(',', (string) $this->option('modules')),
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant created: {$tenant->id}");
        $this->line('  database: ' . $tenant->getInternal('db_name'));
        $this->line('  admin:    ' . $this->argument('email'));
        $this->line('  password: ' . $password);

        return self::SUCCESS;
    }
}
