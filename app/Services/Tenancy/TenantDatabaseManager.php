<?php

namespace App\Services\Tenancy;

use RuntimeException;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;

/**
 * The library's database manager, with one check taken out.
 *
 * userExists() there runs 'SELECT count(*) FROM mysql.user'. Granting that
 * right means lavoro_provisioner can read every password hash on the server,
 * and that is precisely what this account must not be able to do -- it is built
 * so that it can only reach lavoro_tenant_%.
 *
 * The check adds nothing here either. The user name is random per customer, and
 * creating one is preceded by DROP USER IF EXISTS, so a leftover account is not
 * in the way. Without this override, creating any customer broke on "SELECT
 * command denied".
 */
class TenantDatabaseManager extends PermissionControlledMySQLDatabaseManager
{
    public function userExists(string $username): bool
    {
        return false;
    }

    /**
     * Creates a customer's login and grants it rights on its own database only.
     *
     * Handing out those rights goes through a procedure and not through a GRANT
     * here. MySQL and MariaDB weigh a GRANT naming a database against a row for
     * exactly that name, never against the wildcard the provisioner holds:
     * creating lavoro_tenant_acme works, granting rights on it does not (error
     * 1044). The only sufficient variant would be rights on every database, and
     * that is exactly what this account may not have.
     *
     * The procedure runs as whoever created it (root) and refuses every name
     * outside the customer namespace. See scripts/tenancy/setup-mysql.sh.
     */
    public function createUser(DatabaseConfig $config): bool
    {
        $username = (string) $config->getUsername();

        /**
         * The name goes into the statement unquoted, so it has to be beyond
         * suspicion. The generator only produces letters and digits; should
         * anything else ever appear, it stops here and not halfway through a
         * CREATE USER.
         */
        if (preg_match('/[^A-Za-z0-9_]/', $username)) {
            throw new RuntimeException("Ongeldige naam voor een klantlogin: '{$username}'.");
        }

        /**
         * No ? for the password: CREATE USER is DDL and takes no placeholders
         * -- MySQL literally trips over the question mark. The password is
         * therefore quoted by PDO itself.
         */
        $password = $this->database()->getPdo()->quote((string) $config->getPassword());

        $this->database()->statement("CREATE USER `{$username}`@`%` IDENTIFIED BY {$password}");

        [$schema, $procedure] = $this->grantProcedure();

        /**
         * Without placeholders here too. A CALL does accept them, but both
         * values have been checked above and the procedure checks them again
         * itself, so there is nothing to gain -- and creating a customer is no
         * place to discover that an assumption about placeholders was wrong.
         */
        $pdo = $this->database()->getPdo();
        $arguments = $pdo->quote((string) $config->getName()) . ', ' . $pdo->quote($username);

        return $this->database()->statement("CALL `{$schema}`.`{$procedure}`({$arguments})");
    }

    /** @return array{0: string, 1: string} */
    private function grantProcedure(): array
    {
        $configured = (string) config('tenancy.database.grant_procedure', 'lavoro_admin.grant_tenant_access');
        $parts = explode('.', $configured, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new RuntimeException(
                "tenancy.database.grant_procedure hoort 'database.procedure' te zijn, niet '{$configured}'."
            );
        }

        return $parts;
    }
}
