<?php

namespace App\Services\Tenancy;

use RuntimeException;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;

/**
 * De databasebeheerder van de bibliotheek, met één controle eruit.
 *
 * userExists() doet daar 'SELECT count(*) FROM mysql.user'. Dat recht geven
 * betekent dat lavoro_provisioner elke wachtwoordhash op de server kan lezen,
 * en dat is precies wat dit account niet mag kunnen -- het is er juist op
 * gebouwd dat het alleen bij lavoro_tenant_% kan.
 *
 * De controle voegt hier ook niets toe. De gebruikersnaam is per klant
 * willekeurig, en het aanmaken gaat vooraf door DROP USER IF EXISTS, dus een
 * achtergebleven account zit niets in de weg. Zonder deze regel liep het
 * aanmaken van elke klant stuk op "SELECT command denied".
 */
class TenantDatabaseManager extends PermissionControlledMySQLDatabaseManager
{
    public function userExists(string $username): bool
    {
        return false;
    }

    /**
     * Maakt de login van een klant en geeft hem rechten op alleen zijn eigen
     * database.
     *
     * Het uitdelen gaat via een procedure en niet via GRANT hier. MySQL en
     * MariaDB wegen een GRANT die een database bij naam noemt af tegen een rij
     * die exact op die naam staat, nooit tegen het jokerteken dat de provisioner
     * heeft: lavoro_tenant_acme aanmaken lukt, er rechten op uitdelen niet
     * (fout 1044). De enige toereikende variant zou rechten op elke database
     * zijn, en juist dat mag dit account niet.
     *
     * De procedure draait als degene die hem heeft aangemaakt (root) en weigert
     * elke naam buiten de klantnaamruimte. Zie scripts/tenancy/setup-mysql.sh.
     */
    public function createUser(DatabaseConfig $config): bool
    {
        $username = $config->getUsername();

        $this->database()->statement(
            'CREATE USER `' . $username . '`@`%` IDENTIFIED BY ?', [$config->getPassword()]
        );

        [$schema, $procedure] = $this->grantProcedure();

        return $this->database()->statement(
            'CALL `' . $schema . '`.`' . $procedure . '`(?, ?)', [$config->getName(), $username]
        );
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
