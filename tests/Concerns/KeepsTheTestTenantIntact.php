<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Puts the test tenant back after a command that writes outside the test.
 *
 * A command with RunsAsProvisioner switches the central connection to that
 * account and purges it, and purging drops the connection the suite's
 * transaction lives in: what the test wrote first is rolled back, and what the
 * command writes after is committed for real. The next test in the same run
 * then finds the customer renamed, on another package, or registered on a
 * database it never touched.
 *
 * So anything such a test needs to be true beforehand is written over a
 * connection of its own, and the row is put back afterwards.
 */
trait KeepsTheTestTenantIntact
{
    use OutsideTheTestTransaction;

    /** @var array<string, mixed> */
    private array $test_tenant_was = [];

    /** @var array<int, string> */
    private array $tenants_before = [];

    protected function rememberTheTestTenant(): void
    {
        $this->test_tenant_was = (array) DB::connection('central')
            ->table('tenants')->where('id', 'test-tenant')->first();

        $this->tenants_before = DB::connection('central')->table('tenants')->pluck('id')->all();
    }

    /**
     * There is one real tenant database in the suite and the test tenant is
     * registered on it. A command that adopts a database reads that as an
     * import it has already done, so for a first import the registration is
     * parked -- putTheTestTenantBack() writes it back.
     */
    protected function parkTheTestTenantRegistration(): void
    {
        $this->outsideTheTransaction()->table('tenants')->where('id', 'test-tenant')->update([
            'data' => DB::raw("JSON_SET(data, '$.tenancy_db_name', 'lavoro_test_tenant_parked')"),
        ]);
    }

    protected function putTheTestTenantBack(): void
    {
        if (!$this->test_tenant_was) {
            return;
        }

        /** Rows, not models: deleting a tenant drops a database. */
        $this->outsideTheTransaction()->table('tenants')
            ->whereNotIn('id', $this->tenants_before)->delete();

        $this->outsideTheTransaction()->table('tenants')
            ->where('id', 'test-tenant')->update($this->test_tenant_was);

        $this->outsideTheTransaction()->table('user_tenant_lookups')->delete();
    }
}
