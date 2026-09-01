<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Een tweede klant naast de vaste testklant, om te bewijzen dat ze elkaar niet
 * zien.
 *
 * De gewone opzet zet elke test in een transactie, maar dat werkt hier niet:
 * van tenant wisselen gooit de verbinding weg en daarmee de transactie
 * (gemeten: transactieniveau 1 wordt 0 na een wissel). Deze tweede database
 * wordt daarom niet teruggedraaid maar leeggemaakt aan het begin van elke test
 * die hem gebruikt. Dat is traag genoeg om niet standaard te doen en snel
 * genoeg voor de handvol tests die over afscherming gaan.
 */
trait UsesASecondTenant
{
    private static bool $second_tenant_prepared = false;

    private const SECOND_ID = 'test-tenant-two';

    private const SECOND_DATABASE = 'lavoro_test_tenant_two';

    protected function secondTenant(): Tenant
    {
        if (!static::$second_tenant_prepared) {
            $this->createSecondTenant();
            static::$second_tenant_prepared = true;
        }

        $tenant = Tenant::on('central')->findOrFail(self::SECOND_ID);

        $this->emptySecondTenant($tenant);

        return $tenant;
    }

    /** De vaste testklant, waar de rest van de suite ook in draait. */
    protected function firstTenant(): Tenant
    {
        return Tenant::on('central')->findOrFail(tenancy()->tenant->getTenantKey());
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    protected function asTenant(Tenant $tenant, callable $work): mixed
    {
        return Tenancy::within($tenant, $work);
    }

    private function createSecondTenant(): void
    {
        DB::connection('central')->statement('DROP DATABASE IF EXISTS `' . self::SECOND_DATABASE . '`');

        Tenant::on('central')->where('id', self::SECOND_ID)->get()->each(function (Tenant $stale) {
            if ($stale->tenancy_db_username) {
                DB::connection('central')->statement(
                    "DROP USER IF EXISTS '{$stale->tenancy_db_username}'@'%'"
                );
            }

            $stale->delete();
        });

        Tenant::create([
            'id' => self::SECOND_ID,
            'name' => 'Tweede testklant',
            'tenancy_db_name' => self::SECOND_DATABASE,
            'package_key' => 'enterprise',
            'modules' => ['quotes', 'invoices', 'assistant'],
            'storage_limit_gb' => 500,
        ]);
    }

    /**
     * Alleen de tabellen waar de afschermingstests iets in zetten. Alles
     * leeghalen zou de gezaaide rollen en fases ook weggooien, en die zijn
     * nodig om een gebruiker te kunnen maken.
     */
    private function emptySecondTenant(Tenant $tenant): void
    {
        $this->asTenant($tenant, function () {
            DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=0');

            foreach (['users', 'customers', 'activities', 'activityables', 'images', 'imageables'] as $table) {
                if (DB::connection('tenant')->getSchemaBuilder()->hasTable($table)) {
                    DB::connection('tenant')->table($table)->delete();
                }
            }

            DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=1');
        });
    }
}
