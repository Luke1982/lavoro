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

        /**
         * The login it had until now. Every round generates a new random name,
         * so dropping only the new one leaves the old account standing: every
         * right on this customer's database, with a password that is written
         * down nowhere any more. MySQL keeps its grants when the database is
         * dropped, so it is waiting for the name to come back too. This runs
         * again on a customer that is already here -- an installation imported
         * a second time.
         */
        $previous = $tenant->tenancy_db_username;

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
        $connection = DB::connection(config('tenancy.database.template_tenant_connection', 'mysql'));

        foreach (array_unique(array_filter([$previous, $username])) as $name) {
            $connection->statement("DROP USER IF EXISTS '{$name}'@'%'");
        }

        $manager->createUser($tenant->database());
    }
}
