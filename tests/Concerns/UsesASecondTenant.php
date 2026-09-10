<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * A second customer next to the fixed test customer, to prove they do not see
 * each other.
 *
 * The ordinary setup puts every test in a transaction, but that does not work
 * here: switching tenant throws the connection away and the transaction with it
 * (measured: transaction level 1 becomes 0 after a switch). This second database
 * is therefore not rolled back but emptied at the start of every test that uses
 * it. That is slow enough not to do by default and fast enough for the handful
 * of tests about separation.
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

    /** The fixed test customer, the one the rest of the suite runs in as well. */
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
     * Only the tables the separation tests put something in. Emptying
     * everything would throw the seeded roles and stages away too, and those
     * are needed to be able to create a user.
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
