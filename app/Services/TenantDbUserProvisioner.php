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

        if (!$manager instanceof PermissionControlledMySQLDatabaseManager) {
            throw new \RuntimeException('The configured MySQL manager does not manage database users.');
        }

        /**
         * No userExists(): it reads mysql.user, and granting SELECT on that
         * means the provisioner can read every password hash on the server.
         * DROP USER IF EXISTS makes do with the CREATE USER right it already
         * has, and does the same work.
         */
        DB::connection(config('tenancy.database.template_tenant_connection', 'mysql'))
            ->statement("DROP USER IF EXISTS '{$username}'@'%'");

        $manager->createUser($tenant->database());
    }
}
