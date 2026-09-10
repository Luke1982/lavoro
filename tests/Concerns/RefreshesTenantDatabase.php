<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * One test tenant per run, and every test in a transaction on both connections.
 *
 * A database per test would be correct and unworkably slow; a transaction that
 * rolls back gives the same separation. What it does not give: auto-increments
 * starting over, and code that commits itself escapes it.
 */
trait RefreshesTenantDatabase
{
    private static bool $prepared = false;

    protected function setUpTenancy(): void
    {
        $central = config('database.connections.central.database');

        if (!str_contains($central, 'test')) {
            throw new \RuntimeException("Weigeren te draaien: '{$central}' ziet er niet uit als een testdatabase.");
        }

        if (!static::$prepared) {
            Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'central']);

            /**
             * The database and the MySQL login stay behind after a run.
             * Removing them first saves an exception on every following run,
             * and migrating 244 migrations again per run is worth the price
             * against a suite that only runs the first time.
             */
            $database = 'lavoro_test_tenant_test';

            DB::connection('central')->statement("DROP DATABASE IF EXISTS `{$database}`");

            foreach (DB::connection('central')->select(
                'SELECT user FROM mysql.user WHERE user LIKE ?', ['%']
            ) as $row) {
                // nothing: user names are random, we clean up through the tenant row
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
             * The test tenant gets every package and every module. The gates
             * themselves have tests of their own; all other tests are about
             * what sits behind them and should not strand on a subscription.
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

        /**
         * The same manager Laravel's own RefreshDatabase uses. Without it
         * everything that should happen "after the commit" --
         * ShouldHandleEventsAfterCommit listeners, afterCommit jobs -- waits
         * forever, because the enclosing test transaction never commits. This
         * manager knows that and runs such callbacks straight away as long as
         * only the test transaction is open.
         */
        $manager = new DatabaseTransactionsManager(['central', 'tenant']);
        app()->instance('db.transactions', $manager);

        foreach (['central', 'tenant'] as $name) {
            $connection = DB::connection($name);
            $connection->setTransactionManager($manager);

            $dispatcher = $connection->getEventDispatcher();
            $connection->unsetEventDispatcher();
            $connection->beginTransaction();
            $connection->setEventDispatcher($dispatcher);
        }
    }

    protected function tearDownTenancy(): void
    {
        if (tenancy()->initialized) {
            foreach (['tenant', 'central'] as $name) {
                $connection = DB::connection($name);

                $dispatcher = $connection->getEventDispatcher();
                $connection->unsetEventDispatcher();
                $connection->rollBack();
                $connection->setEventDispatcher($dispatcher);
            }

            tenancy()->end();
        }
    }
}
