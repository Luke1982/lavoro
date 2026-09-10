<?php

namespace Tests\Feature\Tenancy;

use Tests\TestCase;

/**
 * Two directories of migrations: database/migrations/ runs against the central
 * database, database/migrations/tenant/ against every customer's.
 *
 * A tenant migration that ends up in the central directory by accident creates
 * its table in lavoro_landlord and nowhere else. Nothing breaks while
 * migrating; it only shows when a customer needs that table.
 */
class MigrationsLiveInTheRightPlaceTest extends TestCase
{
    /**
     * Laravel's own two migrations. They run on the default connection, and
     * that is the central database -- correct, then, but unstated. They are
     * named here so a new exception stands out.
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

    /** And the other way around: a customer migration should not pick the central connection. */
    public function test_no_tenant_migration_writes_to_the_central_database_by_default(): void
    {
        $offenders = [];

        foreach (glob(database_path('migrations/tenant/*.php')) as $path) {
            $source = (string) file_get_contents($path);

            /**
             * Schema::connection('central') in a customer migration is almost
             * always wrong. The exception is a migration that deliberately
             * updates something central, and it says so in its docblock.
             */
            if (str_contains($source, "Schema::connection('central')")) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame([], $offenders, "\nDeze klantmigraties maken tabellen aan in de centrale"
            . " database:\n" . implode("\n", $offenders) . "\n");
    }
}
