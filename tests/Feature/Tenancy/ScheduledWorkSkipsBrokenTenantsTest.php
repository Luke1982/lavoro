<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\MakesLandlordData;
use Tests\TestCase;

/**
 * Scheduled tasks run per customer. One customer with a vanished database made
 * them all fall over: production held 1313 failed jobs, a new one every five
 * minutes, and what was really wrong disappeared among them.
 */
class ScheduledWorkSkipsBrokenTenantsTest extends TestCase
{
    use MakesLandlordData;

    private function brokenTenant(): Tenant
    {
        return Tenant::withoutEvents(fn () => Tenant::on('central')->create([
            'id' => 'kapot-' . uniqid(),
            'name' => 'Kapotte klant',
            'tenancy_db_name' => 'lavoro_test_tenant_bestaat_niet',
            'tenancy_db_username' => 'niemand',
            'tenancy_db_password' => 'geheim',
        ]));
    }

    public function test_a_tenant_whose_database_is_gone_is_not_reachable(): void
    {
        $this->assertFalse(Tenancy::reachable($this->brokenTenant()));
    }

    public function test_the_working_tenant_is_reachable(): void
    {
        $this->assertTrue(Tenancy::reachable(Tenant::on('central')->find('test-tenant')));
    }

    public function test_work_runs_for_the_healthy_tenant_and_skips_the_broken_one(): void
    {
        $broken = $this->brokenTenant();
        $seen = [];

        Tenancy::forEachReachable(function () use (&$seen) {
            $seen[] = tenancy()->tenant->getTenantKey();
        });

        $this->assertContains('test-tenant', $seen, 'de gezonde klant hoort aan de beurt te komen');
        $this->assertNotContains($broken->id, $seen, 'de kapotte klant hoort overgeslagen te worden');
    }

    /** Skipping is not keeping quiet: it belongs in the log. */
    public function test_skipping_a_tenant_is_written_down(): void
    {
        $broken = $this->brokenTenant();

        Log::spy();

        Tenancy::forEachReachable(fn () => null);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'overgeslagen')
                && $context['tenant'] === $broken->id);
    }

    /**
     * Afterwards the same customer should be open as before. If a customer from
     * the loop stays open, everything after it runs in the wrong database --
     * without an error, with the wrong data.
     */
    public function test_it_leaves_the_same_tenant_open_as_before(): void
    {
        $this->brokenTenant();
        $before = tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null;

        Tenancy::forEachReachable(fn () => null);

        $after = tenancy()->initialized ? tenancy()->tenant->getTenantKey() : null;

        $this->assertSame($before, $after);
    }
}
