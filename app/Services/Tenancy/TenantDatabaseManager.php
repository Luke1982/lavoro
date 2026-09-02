<?php

namespace App\Services\Tenancy;

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
}
