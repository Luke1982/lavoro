<?php

namespace Tests\Feature\Landlord;

use App\Models\Tenant;
use App\Services\Invoicer;
use App\Services\TenantDbUserProvisioner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Concerns\MakesLandlordData;
use Tests\TestCase;

/**
 * A customer taken over from a single-customer installation has to be
 * invoiceable, like any other.
 *
 * tenant:setup-existing wrote the row by hand and left the start date empty, and
 * without a start date nothing is ever invoiced. Nothing shows it either: the
 * customer keeps working, the subscription screen shows a monthly amount, and
 * the invoice screen quietly says zero.
 */
class ImportedTenantIsInvoiceableTest extends TestCase
{
    use MakesLandlordData;

    private function import(string $name): Tenant
    {
        /** The MySQL login belongs to provisioning; this test is about the row. */
        $this->app->instance(TenantDbUserProvisioner::class, Mockery::mock(TenantDbUserProvisioner::class)
            ->shouldReceive('provision')->andReturnNull()->getMock());

        /**
         * The command switches its connections to the provisioner, which on a
         * server is a socket only that account may use. Here it points at
         * nothing, so it is aimed at the connection the tests already have --
         * this test is about the row that is written, not about the login.
         */
        config(['database.connections.provisioner' => config('database.connections.central')]);

        /** The addresses in the adopted database belong to the test tenant here. */
        DB::connection('central')->table('user_tenant_lookups')->delete();

        Artisan::call('tenant:setup-existing', [
            'name' => $name,
            'database' => Tenant::on('central')->findOrFail('test-tenant')->getInternal('db_name'),
        ]);

        return Tenant::on('central')->where('name', $name)->firstOrFail();
    }

    public function test_an_imported_tenant_gets_a_start_date(): void
    {
        $tenant = $this->import('Overgenomen BV');

        $this->assertSame(now()->toDateString(), (string) $tenant->subscription_started_on,
            'without a start date this customer is never invoiced, and nothing says so');
    }

    public function test_an_imported_tenant_can_be_invoiced(): void
    {
        $tenant = $this->import('Overgenomen BV');
        $tenant->package_key = 'starter';
        $tenant->save();

        $invoicer = new Invoicer($tenant);

        $this->assertTrue($invoicer->isDue(), 'there is a first month to invoice');
        $this->assertNotEmpty($invoicer->preview()['lines']);
        $this->assertGreaterThan(0, $invoicer->preview()['gross_cents']);
    }

    /** The button offers an invoice; it should only do so when there is one. */
    public function test_nothing_to_invoice_is_not_due(): void
    {
        $tenant = $this->import('Overgenomen BV');
        $tenant->subscription_started_on = null;
        $tenant->save();

        $this->assertFalse((new Invoicer($tenant))->isDue());
    }
}
