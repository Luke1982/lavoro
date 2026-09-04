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
        $username = (string) $config->getUsername();

        /**
         * De naam gaat ongequote de opdracht in, dus hij moet onverdacht zijn.
         * De generator levert alleen letters en cijfers; staat er ooit iets
         * anders, dan stopt het hier en niet halverwege een CREATE USER.
         */
        if (preg_match('/[^A-Za-z0-9_]/', $username)) {
            throw new RuntimeException("Ongeldige naam voor een klantlogin: '{$username}'.");
        }

        /**
         * Geen ? voor het wachtwoord: CREATE USER is DDL en die neemt geen
         * plaatshouders aan -- MySQL struikelt dan letterlijk over het
         * vraagteken. Het wachtwoord wordt daarom door PDO zelf van
         * aanhalingstekens voorzien.
         */
        $password = $this->database()->getPdo()->quote((string) $config->getPassword());

        $this->database()->statement("CREATE USER `{$username}`@`%` IDENTIFIED BY {$password}");

        [$schema, $procedure] = $this->grantProcedure();

        /**
         * Ook hier zonder plaatshouders. Een CALL neemt ze wel aan, maar beide
         * waarden zijn hierboven al nagelopen en de procedure kijkt ze zelf nog
         * eens na, dus er valt niets te winnen -- en het aanmaken van een klant
         * is geen plek om te ontdekken dat een aanname over plaatshouders niet
         * klopte.
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
