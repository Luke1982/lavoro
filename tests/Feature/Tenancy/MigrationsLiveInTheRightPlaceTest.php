<?php

namespace Tests\Feature\Tenancy;

use Tests\TestCase;

/**
 * Twee mappen met migraties: database/migrations/ draait tegen de centrale
 * database, database/migrations/tenant/ tegen die van elke klant.
 *
 * Een tenant-migratie die per ongeluk in de centrale map staat maakt zijn
 * tabel aan in lavoro_landlord en nergens anders. Er gaat niets stuk bij het
 * migreren; het valt pas op als een klant die tabel nodig heeft.
 */
class MigrationsLiveInTheRightPlaceTest extends TestCase
{
    /**
     * De twee migraties van Laravel zelf. Die draaien op de standaard-
     * verbinding, en dat is de centrale database -- klopt dus, maar het staat
     * er niet. Ze staan hier met naam zodat een nieuwe uitzondering opvalt.
     */
    private const ALLOWED_WITHOUT_CONNECTION = [
        '0001_01_01_000001_create_cache_table.php',
        '0001_01_01_000002_create_jobs_table.php',
    ];

    public function test_every_central_migration_says_it_is_central(): void
    {
        $offenders = [];

        foreach (glob(database_path('migrations/*.php')) as $path) {
            $name = basename($path);

            if (in_array($name, self::ALLOWED_WITHOUT_CONNECTION, true)) {
                continue;
            }

            $source = (string) file_get_contents($path);

            if (!str_contains($source, "'central'")) {
                $offenders[] = $name;
            }
        }

        $this->assertSame([], $offenders, "\nDeze migraties staan in de centrale map maar noemen"
            . ' de centrale verbinding niet. Horen ze bij een klant, dan moeten ze naar'
            . " database/migrations/tenant/:\n" . implode("\n", $offenders) . "\n");
    }

    /** En andersom: een klantmigratie hoort de centrale verbinding juist niet te kiezen. */
    public function test_no_tenant_migration_writes_to_the_central_database_by_default(): void
    {
        $offenders = [];

        foreach (glob(database_path('migrations/tenant/*.php')) as $path) {
            $source = (string) file_get_contents($path);

            /**
             * Schema::connection('central') in een klantmigratie is bijna
             * altijd fout. De uitzondering is een migratie die bewust iets
             * centraals bijwerkt, en die zegt dat in zijn toelichting.
             */
            if (str_contains($source, "Schema::connection('central')")) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame([], $offenders, "\nDeze klantmigraties maken tabellen aan in de centrale"
            . " database:\n" . implode("\n", $offenders) . "\n");
    }
}
