<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\Google\DispatchTenantCalendarPullsJob;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesLandlordData;
use Tests\TestCase;

/**
 * Werk dat per klant gepland wordt, hoort bij de worker weer bij die klant uit
 * te komen. De klant reist mee in de payload van de job, en die wordt gevuld op
 * het moment van in de wachtrij zetten -- niet op het moment van uitvoeren.
 *
 * Ging het mis, dan gaf dat geen fout bij het plannen: de job draaide gewoon,
 * tegen de centrale database, en viel daar om op een tabel die daar niet hoort
 * te staan. Op productie was dat elke vijf minuten een mislukte taak.
 */
class QueuedWorkKeepsItsTenantTest extends TestCase
{
    use MakesLandlordData;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);

        /** Alleen kijken naar wat deze test zelf in de wachtrij zet. */
        $this->queued_before = (int) DB::connection('central')->table('jobs')->max('id');

        /**
         * De planner draait zonder klant open, en dat is precies wat deze test
         * moet nabootsen: laat je de klant van de testomgeving openstaan, dan
         * valt de job daar toch nog in en bewijst de test niets.
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
     * De valkuil: een pijlfunctie geeft de PendingDispatch terug, en die zet de
     * job pas in de wachtrij als hij wordt opgeruimd -- buiten de klant.
     */
    public function test_a_job_dispatched_from_a_tenant_carries_that_tenant(): void
    {
        $tenant = Tenant::on('central')->find('test-tenant');

        Tenancy::within($tenant, fn () => DispatchTenantCalendarPullsJob::dispatch());

        $this->assertSame(['test-tenant'], $this->queuedTenants(),
            'de job hoort de klant mee te krijgen waarbinnen hij gepland is');
    }

    /** Zo staat het in routes/console.php: één ronde langs alle klanten. */
    public function test_scheduled_work_queues_one_job_per_tenant_with_its_own_tenant(): void
    {
        Tenancy::forEachReachable(fn () => DispatchTenantCalendarPullsJob::dispatch());

        $queued = $this->queuedTenants();

        $this->assertNotEmpty($queued);
        $this->assertNotContains(null, $queued, 'geen enkele job hoort zonder klant in de wachtrij te komen');
        $this->assertSame($queued, array_unique($queued), 'elke klant hoort één eigen job te krijgen');
    }

    /** Buiten een klant hoort er juist geen klant in de payload te staan. */
    public function test_central_work_stays_central(): void
    {
        DispatchTenantCalendarPullsJob::dispatch();

        $this->assertSame([null], $this->queuedTenants());
    }
}
