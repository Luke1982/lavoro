<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;

class TenantDbUserProvisioner
{
    public function provision(Tenant $tenant): void
    {
        $config = $tenant->database();

        $username = (DatabaseConfig::$usernameGenerator)($tenant);
        $password = (DatabaseConfig::$passwordGenerator)($tenant);

        $tenant->tenancy_db_username = $username;
        $tenant->tenancy_db_password = $password;
        $tenant->save();

        $manager = $config->manager();

        if (! $manager instanceof PermissionControlledMySQLDatabaseManager) {
            throw new \RuntimeException('The configured MySQL manager does not manage database users.');
        }

        /**
         * Geen userExists(): die leest mysql.user, en daar SELECT op geven
         * betekent dat de provisioner elke wachtwoordhash op de server kan
         * lezen. DROP USER IF EXISTS heeft genoeg aan het CREATE USER-recht
         * dat hij toch al heeft, en doet hetzelfde werk.
         */
        DB::connection(config('tenancy.database.template_tenant_connection', 'mysql'))
            ->statement("DROP USER IF EXISTS '{$username}'@'%'");

        $manager->createUser($tenant->database());
    }
}
