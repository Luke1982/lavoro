<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\Google\DispatchTenantCalendarPullsJob;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesLandlordData;
use Tests\TestCase;

/**
 * Work scheduled per customer should arrive back at that customer in the
 * worker. The customer travels along in the job's payload, and that is filled
 * at the moment of queueing -- not at the moment of running.
 *
 * When it went wrong there was no error while scheduling: the job simply ran,
 * against the central database, and fell over there on a table that does not
 * belong there. On production that was a failed job every five minutes.
 */
class QueuedWorkKeepsItsTenantTest extends TestCase
{
    use MakesLandlordData;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);

        /** Only look at what this test queues itself. */
        $this->queued_before = (int) DB::connection('central')->table('jobs')->max('id');

        /**
         * The scheduler runs with no customer open, and that is precisely what
         * this test has to imitate: leave the test environment's customer open
         * and the job falls into it after all, proving nothing.
         */
        tenancy()->end();
    }

    private int $queued_before = 0;

    private function queuedTenants(): array
    {
        return DB::connection('central')->table('jobs')
            ->where('id', '>', $this->queued_before)->orderBy('id')->pluck('payload')
            ->map(fn (string $payload) => json_decode($payload, true)['tenant_id'] ?? null)
            ->all();
    }

    /**
     * The trap: an arrow function returns the PendingDispatch, and that only
     * queues the job once it is cleaned up -- outside the customer.
     */
    public function test_a_job_dispatched_from_a_tenant_carries_that_tenant(): void
    {
        $tenant = Tenant::on('central')->find('test-tenant');

        Tenancy::within($tenant, fn () => DispatchTenantCalendarPullsJob::dispatch());

        $this->assertSame(['test-tenant'], $this->queuedTenants(),
            'de job hoort de klant mee te krijgen waarbinnen hij gepland is');
    }

    /** This is how routes/console.php has it: one round past every customer. */
    public function test_scheduled_work_queues_one_job_per_tenant_with_its_own_tenant(): void
    {
        Tenancy::forEachReachable(fn () => DispatchTenantCalendarPullsJob::dispatch());

        $queued = $this->queuedTenants();

        $this->assertNotEmpty($queued);
        $this->assertNotContains(null, $queued, 'geen enkele job hoort zonder klant in de wachtrij te komen');
        $this->assertSame($queued, array_unique($queued), 'elke klant hoort één eigen job te krijgen');
    }

    /** Outside a customer there should be no customer in the payload at all. */
    public function test_central_work_stays_central(): void
    {
        DispatchTenantCalendarPullsJob::dispatch();

        $this->assertSame([null], $this->queuedTenants());
    }
}
