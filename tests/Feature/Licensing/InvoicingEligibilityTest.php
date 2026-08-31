<?php

namespace Tests\Feature\Licensing;

use App\Models\Central\Invoice;
use App\Models\Central\PendingCharge;
use App\Models\Tenant;
use App\Services\Invoicer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Wanneer valt er iets te factureren? Een dubbele factuur voor dezelfde maand
 * kost een nummer uit een doorlopende reeks en moet met een creditnota
 * teruggedraaid worden, dus dit hoort niet te kunnen.
 */
class InvoicingEligibilityTest extends TestCase
{
    /**
     * De rij wordt rechtstreeks weggeschreven en niet met Tenant::create():
     * dat laatste trapt de pijplijn af die een echte database aanmaakt, en
     * daar gaan deze tests niet over. Er wordt hier alleen gerekend.
     */
    private function tenant(array $attributes = []): Tenant
    {
        $id = 'factuurtest-' . uniqid();

        DB::connection('central')->table('tenants')->insert(array_merge([
            'id' => $id,
            'name' => 'Factuurtest ' . uniqid(),
            'package_key' => 'business',
            'modules' => json_encode([]),
            'subscription_started_on' => '2026-01-12',
            'billing_period' => 'monthly',
            'data' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return Tenant::on('central')->findOrFail($id);
    }

    private function charge(Tenant $tenant, string $kind = 'topup', int $cents = 1000): PendingCharge
    {
        return PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id,
            'description' => 'Extra AI-tegoed',
            'kind' => $kind,
            'amount_cents' => $cents,
        ]);
    }

    public function test_a_started_subscription_is_due_before_it_is_invoiced(): void
    {
        $invoicer = new Invoicer($this->tenant());

        $this->assertTrue($invoicer->isDue(CarbonImmutable::parse('2026-03-15')));
    }

    public function test_a_subscription_that_has_not_started_yet_is_not_due(): void
    {
        $invoicer = new Invoicer($this->tenant(['subscription_started_on' => '2027-01-01']));

        $this->assertFalse($invoicer->isDue(CarbonImmutable::parse('2026-03-15')));
    }

    public function test_the_same_period_cannot_be_invoiced_twice(): void
    {
        $invoicer = new Invoicer($this->tenant());
        $on = CarbonImmutable::parse('2026-03-15');

        $invoicer->issue($on);

        $this->assertFalse($invoicer->isDue($on), 'Een al gefactureerde periode hoort niet nog eens te mogen.');

        $this->expectException(RuntimeException::class);
        $invoicer->issue($on);
    }

    public function test_a_new_period_reopens_the_door(): void
    {
        $tenant = $this->tenant();
        $invoicer = new Invoicer($tenant);

        $invoicer->issue(CarbonImmutable::parse('2026-03-15'));

        $this->assertTrue($invoicer->isDue(CarbonImmutable::parse('2026-04-15')));
    }

    public function test_a_new_charge_reopens_the_door_within_the_same_period(): void
    {
        $tenant = $this->tenant();
        $invoicer = new Invoicer($tenant);
        $on = CarbonImmutable::parse('2026-03-15');

        $invoicer->issue($on);
        $this->assertFalse($invoicer->isDue($on));

        $this->charge($tenant);

        $this->assertTrue($invoicer->isDue($on), 'Bijgekocht tegoed hoort alsnog gefactureerd te kunnen worden.');
    }

    public function test_an_extra_invoice_in_the_same_period_bills_only_the_new_charge(): void
    {
        $tenant = $this->tenant();
        $invoicer = new Invoicer($tenant);
        $on = CarbonImmutable::parse('2026-03-15');

        $first = $invoicer->issue($on);
        $this->charge($tenant, cents: 1000);

        $second = $invoicer->issue($on);

        $this->assertSame(1000, $second->total_cents, 'Het abonnement stond er een tweede keer op.');
        $this->assertSame(['topup'], $second->lines->pluck('kind')->all());
        $this->assertGreaterThan($second->total_cents, $first->total_cents);
    }

    public function test_a_charges_only_invoice_does_not_block_the_next_subscription_invoice(): void
    {
        $tenant = $this->tenant();
        $invoicer = new Invoicer($tenant);

        /** Maart is gefactureerd; daarna koopt de klant tegoed bij. */
        $invoicer->issue(CarbonImmutable::parse('2026-03-15'));
        $this->charge($tenant);
        $charges_only = $invoicer->issue(CarbonImmutable::parse('2026-03-20'));

        $this->assertSame(['topup'], $charges_only->lines->pluck('kind')->all());

        /**
         * Die tussentijdse factuur valt in de periode van april zodra die
         * begint -- hij mag de aprilfactuur niet wegdrukken.
         */
        $april = CarbonImmutable::parse('2026-04-15');

        $this->assertTrue($invoicer->subscriptionIsDue($april));
        $this->assertContains('subscription', $invoicer->issue($april)->lines->pluck('kind')->all());
    }

    public function test_an_invoice_is_never_created_empty(): void
    {
        $invoicer = new Invoicer($this->tenant(['subscription_started_on' => null]));

        $before = Invoice::on('central')->count();

        try {
            $invoicer->issue();
            $this->fail('Er hoort geen lege factuur gemaakt te kunnen worden.');
        } catch (RuntimeException) {
            $this->assertSame($before, Invoice::on('central')->count());
        }
    }
}
