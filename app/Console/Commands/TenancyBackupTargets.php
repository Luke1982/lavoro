<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Wat er geback-upt moet worden, en waarmee je erbij komt.
 *
 * Het deploy-script had dit eerst via tinker opgehaald. Dat is een schil om een
 * REPL: hij schrijft zijn eigen meldingen, vangt exit() af en geeft een
 * exitcode terug die niets zegt over of het gelukt is. In een script dat bij
 * elke fout hoort te stoppen is dat precies verkeerd.
 *
 * Elke regel is: DUMP<tab>database<tab>gebruiker<tab>wachtwoord<tab>host<tab>poort
 * of OVERSLAAN<tab>naam voor een klant waarvan de database niet opengaat.
 */
class TenancyBackupTargets extends Command
{
    protected $signature = 'tenancy:backup-targets';

    protected $description = 'Somt de databases op die geback-upt moeten worden, met hun inloggegevens';

    public function handle(): int
    {
        $central = config('database.connections.central');

        $this->line($this->row($central['database'], $central['username'], $central['password'], $central));

        foreach (Tenant::on('central')->get() as $tenant) {
            if (!Tenancy::reachable($tenant)) {
                $this->line("OVERSLAAN\t" . $tenant->name);

                continue;
            }

            $this->line($this->row(
                $tenant->getInternal('db_name'),
                $tenant->tenancy_db_username,
                $tenant->tenancy_db_password,
                $central,
            ));
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $central */
    private function row(string $database, ?string $user, ?string $password, array $central): string
    {
        return implode("\t", [
            'DUMP', $database, (string) $user, (string) $password,
            $central['host'], $central['port'],
        ]);
    }
}
