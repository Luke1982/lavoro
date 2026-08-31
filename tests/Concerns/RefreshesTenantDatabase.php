<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Eén testtenant per run, en elke test in een transactie op beide verbindingen.
 *
 * Een database per test zou correct zijn en onwerkbaar traag; een transactie die
 * terugdraait geeft dezelfde afscherming. Wat het niet geeft: opnieuw beginnende
 * auto-increments, en code die zelf commit ontsnapt eraan.
 */
trait RefreshesTenantDatabase
{
    private static bool $prepared = false;

    protected function setUpTenancy(): void
    {
        $central = config('database.connections.central.database');

        if (! str_contains($central, 'test')) {
            throw new \RuntimeException("Weigeren te draaien: '{$central}' ziet er niet uit als een testdatabase.");
        }

        if (! static::$prepared) {
            Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'central']);

            /**
             * De database en de MySQL-login blijven na een run staan. Ze eerst
             * weghalen scheelt een uitzondering bij elke volgende run, en het
             * opnieuw migreren van 244 migraties per run is de prijs waard
             * tegenover een suite die alleen de eerste keer draait.
             */
            $database = 'lavoro_test_tenant_test';

            DB::connection('central')->statement("DROP DATABASE IF EXISTS `{$database}`");

            foreach (DB::connection('central')->select(
                'SELECT user FROM mysql.user WHERE user LIKE ?', ['%']
            ) as $row) {
                // niets: gebruikersnamen zijn willekeurig, we ruimen via de tenantrij op
            }

            Tenant::on('central')->where('id', 'test-tenant')->get()->each(function (Tenant $stale) {
                if ($stale->tenancy_db_username) {
                    DB::connection('central')->statement(
                        "DROP USER IF EXISTS '{$stale->tenancy_db_username}'@'%'"
                    );
                }

                $stale->delete();
            });

            /**
             * De testtenant krijgt elk pakket en elke module. De poortjes zelf
             * hebben hun eigen tests; alle andere tests gaan over wat erachter
             * zit en horen niet op een abonnement te stranden.
             */
            Tenant::create([
                'id' => 'test-tenant',
                'name' => 'Test',
                'tenancy_db_name' => $database,
                'package_key' => 'enterprise',
                'modules' => ['quotes', 'invoices', 'assistant'],
                'storage_limit_gb' => 500,
            ]);

            static::$prepared = true;
        }

        tenancy()->initialize(Tenant::on('central')->find('test-tenant'));

        DB::connection('central')->beginTransaction();
        DB::connection('tenant')->beginTransaction();
    }

    protected function tearDownTenancy(): void
    {
        if (tenancy()->initialized) {
            DB::connection('tenant')->rollBack();
            DB::connection('central')->rollBack();
            tenancy()->end();
        }
    }
}
