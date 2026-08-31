<?php

namespace App\Console\Commands;

use App\Services\TenantProvisioner;
use Illuminate\Console\Command;

class CreateTenant extends Command
{
    protected $signature = 'tenant:create
        {name : De bedrijfsnaam}
        {email : Het e-mailadres van de eerste beheerder}
        {--admin-password= : Wachtwoord; leeg laten genereert er een}
        {--package=starter}
        {--modules= : Kommagescheiden}';

    protected $description = 'Maakt een nieuwe tenant met een eigen database, MySQL-login en beheerder';

    public function handle(TenantProvisioner $provisioner): int
    {
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

        $this->info("Tenant aangemaakt: {$tenant->id}");
        $this->line('  database:  ' . $tenant->getInternal('db_name'));
        $this->line('  beheerder: ' . $this->argument('email'));
        $this->line('  wachtwoord: ' . $password);

        return self::SUCCESS;
    }
}
