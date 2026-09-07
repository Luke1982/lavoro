<?php

namespace Tests\Feature\Landlord;

use App\Exceptions\Refusal;
use App\Models\Central\Invoice;
use App\Models\Central\PendingCharge;
use App\Models\Tenant;
use App\Services\Invoicer;
use Carbon\CarbonImmutable;
use Tests\Concerns\MakesLandlordData;
use Tests\TestCase;

/**
 * Wat er op de factuur komt te staan.
 *
 * Uit de migraties: starter 2750, team 8750, jaarkorting 2%, btw 21%.
 */
class InvoiceCalculationTest extends TestCase
{
    use MakesLandlordData;

    /** Standaard maandelijks en al een tijdje lopend, zodat er te rekenen valt. */
    private function tenant(array $attributes = []): Tenant
    {
        return $this->tenantRow(['subscription_started_on' => '2026-01-15', ...$attributes]);
    }

    private function period(Tenant $tenant, string $on): string
    {
        [$start, $end] = (new Invoicer($tenant))->periodFor(CarbonImmutable::parse($on));

        return $start->format('d-m-Y') . ' t/m ' . $end->format('d-m-Y');
    }

    public function test_a_month_runs_from_the_start_day_to_the_day_before_the_next(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-01-15']);

        $this->assertSame('15-01-2026 t/m 14-02-2026', $this->period($tenant, '2026-01-15'));
        $this->assertSame('15-01-2026 t/m 14-02-2026', $this->period($tenant, '2026-02-14'));
        $this->assertSame('15-02-2026 t/m 14-03-2026', $this->period($tenant, '2026-02-15'));
        $this->assertSame('15-06-2026 t/m 14-07-2026', $this->period($tenant, '2026-07-01'));
    }

    /**
     * De maandelijkse factuurdag hoort niet op te schuiven. Met gewoon
     * optellen liep 31 januari over naar 3 maart en lag de factuurdatum daarna
     * voorgoed op de 3e.
     */
    public function test_a_customer_who_started_on_the_thirty_first_keeps_that_day(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-01-31']);

        $this->assertSame('31-01-2026 t/m 27-02-2026', $this->period($tenant, '2026-02-01'));
        $this->assertSame('28-02-2026 t/m 30-03-2026', $this->period($tenant, '2026-03-01'));
        $this->assertSame('31-03-2026 t/m 29-04-2026', $this->period($tenant, '2026-04-01'));
        $this->assertSame('31-05-2026 t/m 29-06-2026', $this->period($tenant, '2026-06-01'));
    }

    public function test_a_customer_who_started_on_the_thirtieth_keeps_that_day_too(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-08-31']);

        $this->assertSame('31-08-2026 t/m 29-09-2026', $this->period($tenant, '2026-09-01'));
        $this->assertSame('30-09-2026 t/m 30-10-2026', $this->period($tenant, '2026-10-01'));
        $this->assertSame('31-10-2026 t/m 29-11-2026', $this->period($tenant, '2026-11-01'));
    }

    public function test_a_yearly_period_runs_twelve_months(): void
    {
        $tenant = $this->tenant([
            'billing_period' => 'yearly',
            'subscription_started_on' => '2026-03-01',
        ]);

        $this->assertSame('01-03-2026 t/m 28-02-2027', $this->period($tenant, '2026-09-09'));
        $this->assertSame('01-03-2027 t/m 29-02-2028', $this->period($tenant, '2027-03-01'));
    }

    public function test_the_subscription_is_due_until_it_has_been_invoiced(): void
    {
        $tenant = $this->tenant();
        $invoicer = new Invoicer($tenant);
        $on = CarbonImmutable::parse('2026-02-15');

        $this->assertTrue($invoicer->subscriptionIsDue($on));

        $invoicer->issue($on);

        $this->assertFalse($invoicer->subscriptionIsDue($on));
        $this->assertTrue($invoicer->subscriptionIsDue(CarbonImmutable::parse('2026-03-15')));
    }

    public function test_a_subscription_that_has_not_started_is_never_due(): void
    {
        $invoicer = new Invoicer($this->tenant(['subscription_started_on' => null]));

        $this->assertFalse($invoicer->subscriptionIsDue(CarbonImmutable::parse('2026-02-15')));
    }

    public function test_a_period_that_has_not_begun_is_not_due_yet(): void
    {
        $invoicer = new Invoicer($this->tenant(['subscription_started_on' => '2026-12-01']));

        $this->assertFalse($invoicer->subscriptionIsDue(CarbonImmutable::parse('2026-02-15')));
    }

    /**
     * Een tussentijdse factuur voor bijgekocht tegoed valt in dezelfde periode
     * als de maandfactuur. Die mag het abonnement niet wegdrukken.
     */
    public function test_an_extra_invoice_in_the_same_period_does_not_cancel_the_subscription(): void
    {
        $tenant = $this->tenant();
        $on = CarbonImmutable::parse('2026-02-20');

        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id,
            'description' => 'Extra AI-tegoed',
            'kind' => 'topup',
            'amount_cents' => 1000,
        ]);

        $invoice = (new Invoicer($tenant))->issue($on);
        $invoice->lines()->where('kind', 'subscription')->delete();

        $this->assertTrue((new Invoicer($tenant))->subscriptionIsDue($on));
    }

    public function test_a_year_is_charged_as_twelve_months_at_once(): void
    {
        $tenant = $this->tenant(['package_key' => 'team', 'billing_period' => 'yearly']);
        $preview = (new Invoicer($tenant))->preview(CarbonImmutable::parse('2026-02-01'));

        $this->assertSame(8750 * 12, $preview['subtotal_cents']);
        $this->assertStringContainsString('(12 maanden)', $preview['lines'][0]['description']);
    }

    public function test_the_yearly_discount_is_two_percent_of_the_subscription(): void
    {
        $tenant = $this->tenant(['package_key' => 'team', 'billing_period' => 'yearly']);
        $preview = (new Invoicer($tenant))->preview(CarbonImmutable::parse('2026-02-01'));

        $this->assertSame((int) round(8750 * 12 * 0.02), $preview['discount_cents']);
        $this->assertSame(8750 * 12 - $preview['discount_cents'], $preview['total_cents']);
    }

    public function test_paying_per_month_gives_no_yearly_discount(): void
    {
        $preview = (new Invoicer($this->tenant()))->preview(CarbonImmutable::parse('2026-02-01'));

        $this->assertSame(0, $preview['discount_cents']);
    }

    /**
     * De jaarkorting hoort bij het abonnement. Wie tegoed bijkoopt of een
     * verrekening krijgt, hoort daar geen twee procent op te krijgen omdat hij
     * toevallig per jaar betaalt.
     */
    public function test_the_yearly_discount_skips_top_ups_and_settlements(): void
    {
        $tenant = $this->tenant(['package_key' => 'team', 'billing_period' => 'yearly']);

        foreach ([['topup', 5000], ['proration', 3000]] as [$kind, $amount]) {
            PendingCharge::on('central')->create([
                'tenant_id' => $tenant->id,
                'description' => 'Losse post',
                'kind' => $kind,
                'amount_cents' => $amount,
            ]);
        }

        $preview = (new Invoicer($tenant))->preview(CarbonImmutable::parse('2026-02-01'));

        $this->assertSame(8750 * 12 + 5000 + 3000, $preview['subtotal_cents']);
        $this->assertSame((int) round(8750 * 12 * 0.02), $preview['discount_cents']);
    }

    public function test_vat_is_charged_over_the_amount_after_discount(): void
    {
        $tenant = $this->tenant(['package_key' => 'team', 'billing_period' => 'yearly']);
        $preview = (new Invoicer($tenant))->preview(CarbonImmutable::parse('2026-02-01'));

        $net = 8750 * 12 - (int) round(8750 * 12 * 0.02);

        $this->assertSame($net, $preview['total_cents']);
        $this->assertSame(21, $preview['vat_percent']);
        $this->assertSame((int) round($net * 0.21), $preview['vat_cents']);
        $this->assertSame($net + (int) round($net * 0.21), $preview['gross_cents']);
    }

    public function test_the_lines_add_up_to_the_subtotal_and_the_gross_is_what_is_collected(): void
    {
        $tenant = $this->tenant([
            'package_key' => 'business',
            'billing_period' => 'yearly',
            'extra_field_seats' => 3,
            'modules' => ['quotes', 'invoices'],
            'storage_limit_gb' => 130,
            'discount_percent' => 7,
        ]);

        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id,
            'description' => 'Extra AI-tegoed',
            'kind' => 'topup',
            'amount_cents' => 2500,
        ]);

        $preview = (new Invoicer($tenant))->preview(CarbonImmutable::parse('2026-02-01'));

        $this->assertSame(
            array_sum(array_column($preview['lines'], 'amount_cents')),
            $preview['subtotal_cents'],
        );
        $this->assertSame(
            $preview['subtotal_cents'] - $preview['discount_cents'],
            $preview['total_cents'],
        );
        $this->assertSame(
            $preview['total_cents'] + $preview['vat_cents'],
            $preview['gross_cents'],
        );
    }

    /**
     * Wisselen op de eerste dag van een periode die nog niet gefactureerd is:
     * er is nog geen dag op het oude pakket voorbij, dus er valt niets te
     * verrekenen en het nieuwe pakket staat er gewoon vol op. Hier kwam het
     * verschil er eerst nog een tweede keer bij.
     */
    public function test_a_switch_on_the_first_day_of_an_uninvoiced_period_gets_no_settlement(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-02-04']);
        $on = CarbonImmutable::parse('2026-02-04');

        $this->assertNull((new Invoicer($tenant))->prorate(2750, 8750, $on));

        $tenant->forceFill(['package_key' => 'team'])->save();
        $preview = (new Invoicer($tenant))->preview($on);

        $this->assertCount(1, $preview['lines']);
        $this->assertSame(8750, $preview['subtotal_cents']);
    }

    public function test_a_switch_halfway_an_invoiced_period_settles_the_days_that_are_left(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);

        (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        $charge = (new Invoicer($tenant))->prorate(2750, 8750, CarbonImmutable::parse('2026-03-16'));

        $this->assertNotNull($charge);
        $this->assertSame((int) round(6000 * 16 / 31), $charge->amount_cents);
        $this->assertSame('proration', $charge->kind);
        $this->assertStringContainsString('16 van 31 dagen', $charge->description);
    }

    public function test_a_downgrade_settles_as_money_back(): void
    {
        $tenant = $this->tenant(['package_key' => 'team', 'subscription_started_on' => '2026-03-01']);

        (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        $charge = (new Invoicer($tenant))->prorate(8750, 2750, CarbonImmutable::parse('2026-03-16'));

        $this->assertNotNull($charge);
        $this->assertSame(-(int) round(6000 * 16 / 31), $charge->amount_cents);
    }

    public function test_a_switch_to_the_same_price_settles_nothing(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);

        (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        $this->assertNull((new Invoicer($tenant))->prorate(2750, 2750, CarbonImmutable::parse('2026-03-16')));
    }

    /** Op de laatste dag valt er nog precies een dag te verrekenen, niet nul. */
    public function test_a_switch_on_the_last_day_of_a_period_settles_one_day(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);

        (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        $charge = (new Invoicer($tenant))->prorate(2750, 8750, CarbonImmutable::parse('2026-03-31'));

        $this->assertSame((int) round(6000 * 1 / 31), $charge->amount_cents);
        $this->assertStringContainsString('1 van 31 dagen', $charge->description);
    }

    public function test_a_yearly_customer_settles_over_twelve_months(): void
    {
        $tenant = $this->tenant([
            'billing_period' => 'yearly',
            'subscription_started_on' => '2026-01-01',
        ]);

        (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-01-01'));

        $charge = (new Invoicer($tenant))->prorate(2750, 8750, CarbonImmutable::parse('2026-07-01'));

        $this->assertSame((int) round(6000 * 12 * 184 / 365), $charge->amount_cents);
    }

    /**
     * Over twee periodes heen moet de klant precies betalen voor wat hij had:
     * de oude prijs voor de dagen tot de wissel, de nieuwe voor de rest.
     */
    public function test_an_upgrade_costs_the_old_price_until_the_switch_and_the_new_price_after(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);

        $first = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        $this->assertSame(2750, $first->total_cents);

        $tenant->forceFill(['package_key' => 'team'])->save();
        (new Invoicer($tenant))->prorate(2750, 8750, CarbonImmutable::parse('2026-03-16'));

        $second = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-04-01'));

        $settlement = (int) round(6000 * 16 / 31);

        $this->assertSame(8750 + $settlement, $second->total_cents);

        $days_on_starter = 15;
        $days_on_team = 16;

        $this->assertSame(
            (int) round(2750 * $days_on_starter / 31) + (int) round(8750 * $days_on_team / 31) + 8750,
            $first->total_cents + $second->total_cents,
            'samen hoort dit gelijk te zijn aan starter tot de wissel, team erna, plus de nieuwe maand',
        );
    }

    public function test_an_empty_invoice_is_refused(): void
    {
        $tenant = $this->tenant();
        $on = CarbonImmutable::parse('2026-02-15');

        (new Invoicer($tenant))->issue($on);

        $this->expectException(Refusal::class);

        (new Invoicer($tenant))->issue($on);
    }

    public function test_numbers_run_on_per_year_and_are_never_reused(): void
    {
        $first = $this->tenant(['subscription_started_on' => '2026-02-01']);
        $second = $this->tenant(['subscription_started_on' => '2026-02-01']);

        Invoice::on('central')->create([
            'number' => '2026-LVR-8', 'tenant_id' => $first->id,
            'period_start' => '2025-01-01', 'period_end' => '2025-01-31',
            'issued_on' => '2025-01-01', 'due_on' => '2025-01-15',
            'subtotal_cents' => 0, 'discount_cents' => 0, 'total_cents' => 0,
            'vat_percent' => 21, 'vat_cents' => 0, 'gross_cents' => 0,
        ]);

        $invoice = (new Invoicer($first))->issue(CarbonImmutable::parse('2026-02-01'));
        $this->assertSame('2026-LVR-9', $invoice->number);

        $invoice->delete();

        $next = (new Invoicer($second))->issue(CarbonImmutable::parse('2026-02-01'));
        $this->assertSame('2026-LVR-9', $next->number, 'het hoogste bestaande nummer bepaalt het volgende');
    }

    public function test_what_is_previewed_is_what_gets_stored(): void
    {
        $tenant = $this->tenant([
            'package_key' => 'team',
            'billing_period' => 'yearly',
            'subscription_started_on' => '2026-02-01',
            'storage_limit_gb' => 90,
        ]);

        $on = CarbonImmutable::parse('2026-02-01');
        $preview = (new Invoicer($tenant))->preview($on);
        $invoice = (new Invoicer($tenant))->issue($on);

        $this->assertSame($preview['subtotal_cents'], $invoice->subtotal_cents);
        $this->assertSame($preview['discount_cents'], $invoice->discount_cents);
        $this->assertSame($preview['total_cents'], $invoice->total_cents);
        $this->assertSame($preview['vat_cents'], $invoice->vat_cents);
        $this->assertSame($preview['gross_cents'], $invoice->gross_cents);
        $this->assertCount(count($preview['lines']), $invoice->lines);
        $this->assertSame('01-02-2026', $invoice->period_start->format('d-m-Y'));
        $this->assertSame('31-01-2027', $invoice->period_end->format('d-m-Y'));
    }

    public function test_a_settlement_is_charged_once_and_then_belongs_to_its_invoice(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);

        (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        $tenant->forceFill(['package_key' => 'team'])->save();
        $charge = (new Invoicer($tenant))->prorate(2750, 8750, CarbonImmutable::parse('2026-03-16'));

        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-04-01'));

        $this->assertSame(8750 + $charge->amount_cents, $invoice->total_cents);
        $this->assertSame($invoice->id, $charge->fresh()->invoice_id);
        $this->assertCount(0, (new Invoicer($tenant))->pendingCharges());

        $later = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-05-01'));

        $this->assertSame(8750, $later->total_cents, 'team zonder de verrekening van vorige maand');
    }

    public function test_a_credit_smaller_than_the_bill_is_simply_deducted(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);

        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id,
            'description' => 'Verrekening terug',
            'kind' => 'proration',
            'amount_cents' => -1000,
        ]);

        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        $this->assertSame(2750 - 1000, $invoice->total_cents);
    }

    /**
     * Het tegoed van een klant mag niet verdampen. Zonder de grens hieronder
     * werd de factuur nul euro terwijl de tegoedpost wel als verwerkt werd
     * afgestempeld -- of liep de opslag stuk op een negatief bedrag.
     */
    public function test_a_credit_bigger_than_the_bill_keeps_standing(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);

        (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id,
            'description' => 'Verrekening terug',
            'kind' => 'proration',
            'amount_cents' => -5000,
        ]);

        $on = CarbonImmutable::parse('2026-03-16');
        $invoicer = new Invoicer($tenant);

        $this->assertSame(-5000, $invoicer->preview($on)['total_cents']);
        $this->assertFalse($invoicer->isDue($on));

        try {
            $invoicer->issue($on);
            $this->fail('een negatieve factuur hoort geweigerd te worden');
        } catch (Refusal $refusal) {
            $this->assertStringContainsString('tegoed', $refusal->getMessage());
        }

        $this->assertCount(1, $invoicer->pendingCharges());
        $this->assertSame(1, Invoice::on('central')->where('tenant_id', $tenant->id)->count());
    }

    public function test_a_standing_credit_comes_off_the_next_invoice(): void
    {
        $tenant = $this->tenant(['package_key' => 'team', 'subscription_started_on' => '2026-03-01']);

        (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id,
            'description' => 'Verrekening terug',
            'kind' => 'proration',
            'amount_cents' => -5000,
        ]);

        $next = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-04-01'));

        $this->assertSame(8750 - 5000, $next->total_cents);
        $this->assertCount(0, (new Invoicer($tenant))->pendingCharges());
    }

    public function test_top_up_money_is_charged_once_at_what_was_paid(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);

        $this->actingAs($this->landlord(), 'landlord')
            ->post(route('landlord.topup', $tenant->id), ['paid_euro' => '12.50', 'note' => 'test'])
            ->assertRedirect();

        $charge = PendingCharge::on('central')->where('tenant_id', $tenant->id)->first();

        $this->assertSame(1250, $charge->amount_cents);
        $this->assertSame('topup', $charge->kind);

        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        $this->assertSame(2750 + 1250, $invoice->total_cents);

        $later = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-04-01'));

        $this->assertSame(2750, $later->total_cents, 'bijkoop hoort maar een keer op een factuur te staan');
    }

    public function test_the_numbers_start_again_in_a_new_year(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-12-01']);

        $this->assertSame(
            '2026-LVR-1',
            (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-12-01'))->number,
        );
        $this->assertSame(
            '2027-LVR-1',
            (new Invoicer($tenant))->issue(CarbonImmutable::parse('2027-01-01'))->number,
        );
    }

    /**
     * Wordt de startdatum gecorrigeerd, dan verschuift de periode-indeling.
     * Dagen die al gefactureerd zijn mogen daardoor niet opnieuw op een
     * factuur belanden.
     */
    public function test_days_that_are_already_paid_are_never_charged_a_second_time(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);

        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        $this->assertSame('01-03-2026', $invoice->period_start->format('d-m-Y'));

        $tenant->forceFill(['subscription_started_on' => '2026-03-10'])->save();

        $invoicer = new Invoicer($tenant);

        $this->assertSame('10-03-2026 t/m 09-04-2026', $this->period($tenant, '2026-03-15'));
        $this->assertFalse(
            $invoicer->subscriptionIsDue(CarbonImmutable::parse('2026-03-15')),
            'de dagen vanaf 10 maart staan al op de factuur van 1 maart',
        );
        $this->assertTrue($invoicer->subscriptionIsDue(CarbonImmutable::parse('2026-04-15')));
    }

    /**
     * Wisselen halverwege een maand die nog niet gefactureerd is. De factuur
     * die eraan komt rekent het nieuwe pakket over de hele maand, ook over de
     * dagen dat de klant nog op het oude zat. Die dagen horen er als tegoed af.
     *
     * Precies het geval dat gemeld werd: begonnen op 1 september op starter,
     * op 7 september naar team. Zes dagen starter, vierentwintig dagen team.
     */
    public function test_a_switch_halfway_an_uninvoiced_period_credits_the_days_on_the_old_package(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);
        $on = CarbonImmutable::parse('2026-09-07');

        $charge = (new Invoicer($tenant))->prorate(2750, 8750, $on);

        $this->assertNotNull($charge, 'er hoort wel degelijk verrekend te worden');
        $this->assertSame(-(int) round(6000 * 6 / 30), $charge->amount_cents);
        $this->assertStringContainsString('6 van 30 dagen op de oude prijs', $charge->description);

        $tenant->forceFill(['package_key' => 'team'])->save();
        $invoice = (new Invoicer($tenant))->issue($on);

        $this->assertSame(
            (int) round(2750 * 6 / 30) + (int) round(8750 * 24 / 30),
            $invoice->total_cents,
            'zes dagen starter plus vierentwintig dagen team',
        );
        $this->assertSame(7550, $invoice->total_cents);
    }

    /**
     * Dezelfde maand kost hetzelfde, of de factuur nu voor of na de wissel
     * gemaakt is. Of dat toevallig zo uitkomt hoort de klant niets te schelen.
     */
    public function test_a_month_costs_the_same_whether_it_was_invoiced_before_or_after_the_switch(): void
    {
        $ideal = (int) round(2750 * 6 / 30) + (int) round(8750 * 24 / 30);
        $on = CarbonImmutable::parse('2026-09-07');

        $before = $this->tenant(['subscription_started_on' => '2026-09-01']);
        (new Invoicer($before))->issue(CarbonImmutable::parse('2026-09-01'));
        $before->forceFill(['package_key' => 'team'])->save();
        $settlement = (new Invoicer($before))->prorate(2750, 8750, $on);
        $second = (new Invoicer($before))->issue($on);

        $this->assertSame($ideal, 2750 + $second->total_cents);
        $this->assertSame($settlement->amount_cents, $second->total_cents);

        $after = $this->tenant(['subscription_started_on' => '2026-09-01']);
        (new Invoicer($after))->prorate(2750, 8750, $on);
        $after->forceFill(['package_key' => 'team'])->save();

        $this->assertSame($ideal, (new Invoicer($after))->issue($on)->total_cents);
    }

    public function test_a_downgrade_halfway_an_uninvoiced_period_charges_the_days_on_the_old_package(): void
    {
        $tenant = $this->tenant(['package_key' => 'team', 'subscription_started_on' => '2026-09-01']);
        $on = CarbonImmutable::parse('2026-09-07');

        $charge = (new Invoicer($tenant))->prorate(8750, 2750, $on);

        $this->assertSame((int) round(6000 * 6 / 30), $charge->amount_cents);

        $tenant->forceFill(['package_key' => 'starter'])->save();

        $this->assertSame(
            (int) round(8750 * 6 / 30) + (int) round(2750 * 24 / 30),
            (new Invoicer($tenant))->issue($on)->total_cents,
        );
    }

    /**
     * Twee wissels in dezelfde nog niet gefactureerde maand horen ook op te
     * tellen tot wat de klant werkelijk gebruikt heeft.
     */
    public function test_two_switches_in_one_month_still_add_up(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);

        (new Invoicer($tenant))->prorate(2750, 8750, CarbonImmutable::parse('2026-09-07'));
        $tenant->forceFill(['package_key' => 'team'])->save();

        (new Invoicer($tenant))->prorate(8750, 16000, CarbonImmutable::parse('2026-09-15'));
        $tenant->forceFill(['package_key' => 'business'])->save();

        $expected = 16000
            - (int) round(6000 * 6 / 30)
            - (int) round(7250 * 14 / 30);

        $this->assertSame($expected, (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-09-15'))->total_cents);
    }

    /**
     * Op de factuur moet te zien zijn waar de verrekening vandaan komt: van
     * welk pakket naar welk, en op welke dag.
     */
    public function test_the_settlement_says_which_packages_it_is_between(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);

        $credit = (new Invoicer($tenant))
            ->prorate(2750, 8750, CarbonImmutable::parse('2026-09-07'), 'Starter', 'Team');

        $this->assertSame(
            'Verrekening pakketwissel 07-09-2026: Starter naar Team (6 van 30 dagen op Starter)',
            $credit->description,
        );

        $invoiced = $this->tenant(['subscription_started_on' => '2026-09-01']);
        (new Invoicer($invoiced))->issue(CarbonImmutable::parse('2026-09-01'));

        $charge = (new Invoicer($invoiced))
            ->prorate(2750, 8750, CarbonImmutable::parse('2026-09-07'), 'Starter', 'Team');

        $this->assertSame(
            'Verrekening pakketwissel 07-09-2026: Starter naar Team (24 van 30 dagen op Team)',
            $charge->description,
        );
    }

    /** Een plek erbij is geen pakketwissel, en hoort dat ook niet te beweren. */
    public function test_a_change_that_leaves_the_package_alone_is_not_called_a_switch(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);

        $charge = (new Invoicer($tenant))
            ->prorate(2750, 3950, CarbonImmutable::parse('2026-09-07'), 'Starter', 'Starter');

        $this->assertStringStartsWith('Verrekening abonnementswijziging 07-09-2026', $charge->description);
        $this->assertStringNotContainsString('pakketwissel', $charge->description);
    }

    /**
     * Staat er een afgesproken prijs op een regel, dan hoort de gewone prijs
     * erbij: over een jaar weet niemand meer waarom er een ander bedrag stond.
     */
    public function test_an_agreed_price_says_what_the_normal_price_is(): void
    {
        $tenant = $this->tenant([
            'price_override_cents' => 14900,
            'modules' => ['quotes'],
            'module_prices' => ['quotes' => 1500],
        ]);

        $lines = (new Invoicer($tenant))->preview(CarbonImmutable::parse('2026-02-01'))['lines'];

        $this->assertSame(
            'Abonnement Lavoro Starter 15-01-2026 t/m 14-02-2026, normaal € 27,50, speciale prijsafspraak',
            $lines[0]['description'],
        );
        $this->assertSame(14900, $lines[0]['amount_cents']);

        $this->assertSame('Offertes, normaal € 27,50, speciale prijsafspraak', $lines[1]['description']);
        $this->assertSame(1500, $lines[1]['amount_cents']);
    }

    public function test_a_normal_price_is_only_mentioned_where_something_was_agreed(): void
    {
        $lines = (new Invoicer($this->tenant(['modules' => ['quotes']])))
            ->preview(CarbonImmutable::parse('2026-02-01'))['lines'];

        foreach ($lines as $line) {
            $this->assertStringNotContainsString('speciale prijsafspraak', $line['description']);
        }
    }

    /** Bij een jaarfactuur staat er ook een jaarbedrag als normale prijs. */
    public function test_the_normal_price_follows_the_billing_period(): void
    {
        $tenant = $this->tenant(['billing_period' => 'yearly', 'price_override_cents' => 14900]);

        $lines = (new Invoicer($tenant))->preview(CarbonImmutable::parse('2026-02-01'))['lines'];

        $this->assertStringContainsString('normaal € 330,00, speciale prijsafspraak', $lines[0]['description']);
        $this->assertSame(14900 * 12, $lines[0]['amount_cents']);
    }

    /**
     * Twee keer opslaan op een dag -- eerst naar Team, dan een prijs voor dat
     * pakket afgesproken -- gaf twee verrekeningsregels met dezelfde
     * omschrijving en tegengestelde bedragen. Samen klopte het, los was het
     * onleesbaar.
     */
    public function test_two_changes_on_one_day_end_up_on_one_line(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);

        $on = CarbonImmutable::parse('2026-09-07');

        (new Invoicer($tenant))->prorate(2750, 8750, $on, 'Starter', 'Team');
        (new Invoicer($tenant))->prorate(8750, 5000, $on, 'Team', 'Team');
        $tenant->forceFill(['package_key' => 'team', 'price_override_cents' => 5000])->save();

        $charges = (new Invoicer($tenant))->pendingCharges();

        $this->assertCount(1, $charges, 'twee wijzigingen, een regel');
        $this->assertSame(-(int) round((5000 - 2750) * 6 / 30), (int) $charges->first()->amount_cents);
        $this->assertSame(
            'Verrekening abonnementswijziging: Starter naar Team,'
                . ' € 27,50 naar € 50,00 per maand (laatste wijziging 07-09-2026)',
            $charges->first()->description,
        );

        $this->assertSame(
            (int) round(2750 * 6 / 30) + (int) round(5000 * 24 / 30),
            (new Invoicer($tenant))->issue($on)->total_cents,
            'zes dagen starter, vierentwintig dagen het afgesproken pakket',
        );
    }

    public function test_changes_that_cancel_each_other_leave_no_line_at_all(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);
        $on = CarbonImmutable::parse('2026-09-07');

        (new Invoicer($tenant))->prorate(2750, 5000, $on);
        (new Invoicer($tenant))->prorate(5000, 2750, $on);

        $this->assertCount(0, (new Invoicer($tenant))->pendingCharges());
    }

    /**
     * Een wijziging die geen pakketwissel is, hoort wel te zeggen wat er dan
     * wel veranderde. 'Abonnementswijziging' alleen legt niets uit.
     */
    public function test_a_change_outside_the_package_names_the_amounts(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);

        $charge = (new Invoicer($tenant))->prorate(2750, 5000, CarbonImmutable::parse('2026-09-07'));

        $this->assertSame(
            'Verrekening abonnementswijziging 07-09-2026: € 27,50 naar € 50,00 per maand'
                . ' (6 van 30 dagen op de oude prijs)',
            $charge->description,
        );
    }

    /**
     * Ook na een tweede wijziging hoort er te staan van welk pakket naar welk.
     * Het samenvoegen gooide dat eerst weg: er stond alleen nog dat er iets
     * gewijzigd was.
     */
    public function test_a_merged_settlement_still_names_both_packages(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);
        $on = CarbonImmutable::parse('2026-09-07');

        (new Invoicer($tenant))->prorate(2750, 8750, $on, 'Starter', 'Team');
        (new Invoicer($tenant))->prorate(8750, 11000, $on, 'Team', 'Team');

        $charge = (new Invoicer($tenant))->pendingCharges()->first();

        $this->assertSame(
            'Verrekening abonnementswijziging: Starter naar Team,'
                . ' € 27,50 naar € 110,00 per maand (laatste wijziging 07-09-2026)',
            $charge->description,
        );
    }

    /** Een wissel die verderop weer teruggedraaid wordt, noemt geen wissel. */
    public function test_a_merged_settlement_that_ends_where_it_started_names_no_switch(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);
        $on = CarbonImmutable::parse('2026-09-07');

        (new Invoicer($tenant))->prorate(2750, 8750, $on, 'Starter', 'Team');
        (new Invoicer($tenant))->prorate(8750, 4000, $on, 'Team', 'Starter');

        $this->assertStringNotContainsString(
            'naar Starter',
            (new Invoicer($tenant))->pendingCharges()->first()->description,
        );
    }

    /**
     * Verrekeningen van twee verschillende maanden horen niet op een hoop.
     *
     * Ze gaan over een ander aantal dagen en over een andere factuur. Bij
     * elkaar opgeteld leveren ze een bedrag op dat bij geen van beide maanden
     * hoort, onder een omschrijving die geen van beide beschrijft.
     */
    public function test_settlements_from_different_periods_stay_apart(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-08-01']);

        (new Invoicer($tenant))->prorate(2750, 8750, CarbonImmutable::parse('2026-08-10'), 'Starter', 'Team');
        (new Invoicer($tenant))->prorate(8750, 11000, CarbonImmutable::parse('2026-09-10'), 'Team', 'Team');

        $charges = (new Invoicer($tenant))->pendingCharges();

        $this->assertCount(2, $charges);
        $this->assertStringContainsString('10-08-2026', $charges[0]->description);
        $this->assertStringContainsString('van 31 dagen', $charges[0]->description);
        $this->assertStringContainsString('10-09-2026', $charges[1]->description);
        $this->assertStringContainsString('van 30 dagen', $charges[1]->description);
    }

    /**
     * Er wordt maar een periode tegelijk gefactureerd. Slaat een maand over,
     * dan komt die uit zichzelf nooit meer terug en verdwijnt er stilzwijgend
     * omzet. Dat hoort in elk geval zichtbaar te zijn.
     */
    public function test_periods_that_were_never_billed_are_reported(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-06-01']);
        $on = CarbonImmutable::parse('2026-09-05');

        $missed = (new Invoicer($tenant))->unbilledPeriods($on);

        $this->assertCount(3, $missed, 'juni, juli en augustus');
        $this->assertSame('01-06-2026', $missed[0]['start']->format('d-m-Y'));
        $this->assertSame('30-06-2026', $missed[0]['end']->format('d-m-Y'));
        $this->assertSame('01-08-2026', $missed[2]['start']->format('d-m-Y'));

        (new Invoicer($tenant))->issue($on);

        $this->assertCount(3, (new Invoicer($tenant))->unbilledPeriods($on), 'september telt niet mee');
    }

    public function test_a_customer_who_is_billed_every_month_has_nothing_outstanding(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-06-01']);

        foreach (['2026-06-01', '2026-07-01', '2026-08-01'] as $date) {
            (new Invoicer($tenant))->issue(CarbonImmutable::parse($date));
        }

        $this->assertSame([], (new Invoicer($tenant))->unbilledPeriods(CarbonImmutable::parse('2026-09-05')));
    }

    public function test_a_customer_without_a_start_date_has_no_missed_periods(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => null]);

        $this->assertSame([], (new Invoicer($tenant))->unbilledPeriods(CarbonImmutable::parse('2026-09-05')));
    }

    /**
     * Wie halverwege de maand een module erbij neemt, betaalt de dagen die er
     * nog van de maand over zijn. Niet de hele maand, en niet niets.
     */
    public function test_a_module_added_halfway_is_charged_for_the_days_that_are_left(): void
    {
        $tenant = $this->tenant([
            'subscription_started_on' => '2026-09-01',
            'modules' => ['assistant'],
            'module_started_on' => ['assistant' => '2026-09-07'],
        ]);

        $lines = (new Invoicer($tenant))->preview(CarbonImmutable::parse('2026-09-07'))['lines'];

        $this->assertSame(
            'AI-assistent 07-09-2026 t/m 30-09-2026 (24 van 30 dagen)',
            $lines[1]['description'],
        );
        $this->assertSame((int) round(2250 * 24 / 30), $lines[1]['amount_cents']);
    }

    public function test_a_module_that_was_there_all_along_is_charged_in_full(): void
    {
        $tenant = $this->tenant([
            'subscription_started_on' => '2026-09-01',
            'modules' => ['assistant'],
            'module_started_on' => ['assistant' => '2026-09-01'],
        ]);

        $lines = (new Invoicer($tenant))->preview(CarbonImmutable::parse('2026-09-07'))['lines'];

        $this->assertSame('AI-assistent', $lines[1]['description']);
        $this->assertSame(2250, $lines[1]['amount_cents']);
    }

    public function test_a_module_from_an_earlier_month_is_charged_in_full(): void
    {
        $tenant = $this->tenant([
            'subscription_started_on' => '2026-09-01',
            'modules' => ['assistant'],
            'module_started_on' => ['assistant' => '2026-06-12'],
        ]);

        $lines = (new Invoicer($tenant))->preview(CarbonImmutable::parse('2026-09-07'))['lines'];

        $this->assertSame(2250, $lines[1]['amount_cents']);
    }

    /**
     * De normale prijs is die uit de catalogus, ook bij een deel van de maand.
     * Naar rato meerekenen gaf een bedrag dat nergens bestaat: 'normaal
     * € 18,00' voor een module die gewoon € 22,50 kost.
     */
    public function test_the_normal_price_stays_the_catalogue_price(): void
    {
        $tenant = $this->tenant([
            'subscription_started_on' => '2026-09-01',
            'modules' => ['assistant'],
            'module_prices' => ['assistant' => 1500],
            'module_started_on' => ['assistant' => '2026-09-07'],
        ]);

        $lines = (new Invoicer($tenant))->preview(CarbonImmutable::parse('2026-09-07'))['lines'];

        $this->assertSame(
            'AI-assistent 07-09-2026 t/m 30-09-2026 (24 van 30 dagen),'
                . ' normaal € 22,50, speciale prijsafspraak',
            $lines[1]['description'],
        );
        $this->assertSame(1200, $lines[1]['amount_cents']);
    }

    /** Een bundel telt vanaf de dag dat hij compleet werd. */
    public function test_a_bundle_counts_from_the_day_it_was_completed(): void
    {
        $tenant = $this->tenant([
            'subscription_started_on' => '2026-09-01',
            'modules' => ['quotes', 'invoices'],
            'module_started_on' => ['quotes' => '2026-08-01', 'invoices' => '2026-09-07'],
        ]);

        $lines = (new Invoicer($tenant))->preview(CarbonImmutable::parse('2026-09-07'))['lines'];

        $this->assertStringContainsString('24 van 30 dagen', $lines[1]['description']);
        $this->assertSame((int) round(4000 * 24 / 30), $lines[1]['amount_cents']);
    }
}
