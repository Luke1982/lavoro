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
 * What ends up on the invoice.
 *
 * From the migrations: starter 2750, team 8750, yearly discount 2%, VAT 21%.
 */
class InvoiceCalculationTest extends TestCase
{
    use MakesLandlordData;

    /** Monthly by default and running for a while, so there is something to compute. */
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
     * The monthly invoice day should not drift. With plain addition 31 January
     * overflowed into 3 March and the invoice date sat on the 3rd forever
     * after.
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
     * An interim invoice for topped up credit falls in the same period as the
     * monthly invoice. It must not push the subscription aside.
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
     * The yearly discount belongs to the subscription. Someone topping up
     * credit or receiving a settlement should not get two percent off it
     * because they happen to pay per year.
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
     * Changing on the first day of a period that has not been invoiced: not a
     * day on the old package has passed, so there is nothing to settle and the
     * new package is simply charged in full. The difference used to be added a
     * second time here.
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

    /** On the last day there is exactly one day left to settle, not zero. */
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
     * Across two periods the customer should pay exactly for what they had: the
     * old price for the days up to the change, the new one for the rest.
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
        $this->assertSame('2026-LVR-10', $next->number,
            'the number of a removed invoice does not come back: two invoices with the same '
            . 'number is what a continuous series must never produce');
    }

    /**
     * An invoice outlives the customer. Removing one used to take their invoices
     * with it, and with them the highest number -- which the next invoice then
     * took over.
     */
    public function test_invoices_survive_the_customer_and_keep_their_number(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-02-01']);
        $other = $this->tenant(['subscription_started_on' => '2026-02-01']);

        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-02-01'));

        $this->assertSame($tenant->name, $invoice->tenant_name, 'the invoice says who it was for');

        $tenant->delete();

        $this->assertNotNull(Invoice::on('central')->find($invoice->id), 'the invoice stays behind');

        $next = (new Invoicer($other))->issue(CarbonImmutable::parse('2026-02-01'));

        $this->assertNotSame($invoice->number, $next->number);
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
     * More credit than there is to invoice becomes a credit note: money back
     * instead of out. At first such credit stayed put until a next invoice came
     * -- but for a customer who has just cancelled it never comes, and then
     * they never got their money back.
     */
    public function test_more_credit_than_there_is_to_bill_becomes_a_credit_note(): void
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
        $this->assertTrue($invoicer->isDue($on));
        $this->assertTrue($invoicer->isCreditNote($on));

        $credit = $invoicer->issue($on);

        $this->assertSame(-5000, $credit->total_cents);
        $this->assertSame(-(int) round(5000 * 1.21), $credit->gross_cents);
        $this->assertCount(0, (new Invoicer($tenant))->pendingCharges(), 'het tegoed is verwerkt');
    }

    /** A credit note cannot be collected: paying back is done by hand. */
    public function test_a_credit_note_is_left_out_of_the_direct_debit_batch(): void
    {
        $tenant = $this->tenant([
            'subscription_started_on' => '2026-03-01',
            'payment_method' => 'direct_debit',
            'iban' => 'NL91ABNA0417164300',
            'mandate_reference' => 'MND-1',
            'mandate_signed_on' => '2026-01-01',
        ]);

        (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id,
            'description' => 'Verrekening terug',
            'kind' => 'proration',
            'amount_cents' => -5000,
        ]);

        $credit = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-16'));

        $this->actingAs($this->landlord(), 'landlord')
            ->get(route('landlord.collections'))
            ->assertInertia(function ($page) use ($credit) {
                $numbers = collect($page->toArray()['props']['invoices'])->pluck('number');

                $this->assertFalse($numbers->contains($credit->number), 'creditfactuur hoort er niet bij');
            });
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
     * Correcting the start date shifts how the periods are divided. Days that
     * have already been invoiced must not end up on an invoice again because of
     * it.
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
     * Changing halfway through a month that has not been invoiced. The invoice
     * that is coming charges the new package over the whole month, including
     * the days the customer was still on the old one. Those days come off as
     * credit.
     *
     * Exactly the case that was reported: started on 1 September on starter, on
     * 7 September to team. Six days starter, twenty-four days team.
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
     * The same month costs the same, whether the invoice was made before or
     * after the change. Whether that happens to line up is no concern of the
     * customer's.
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
     * Two changes in the same uninvoiced month should also add up to what the
     * customer actually used.
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
     * The invoice has to show where the settlement comes from: from which
     * package to which, and on what day.
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

    /** An extra seat is not a package change, and should not claim to be one. */
    public function test_a_change_that_leaves_the_package_alone_is_not_called_a_switch(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-09-01']);

        $charge = (new Invoicer($tenant))
            ->prorate(2750, 3950, CarbonImmutable::parse('2026-09-07'), 'Starter', 'Starter');

        $this->assertStringStartsWith('Verrekening abonnementswijziging 07-09-2026', $charge->description);
        $this->assertStringNotContainsString('pakketwissel', $charge->description);
    }

    /**
     * If a line has an agreed price, the normal price belongs next to it: in a
     * year nobody remembers why a different amount was there.
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

    /** On a yearly invoice the normal price is a yearly amount too. */
    public function test_the_normal_price_follows_the_billing_period(): void
    {
        $tenant = $this->tenant(['billing_period' => 'yearly', 'price_override_cents' => 14900]);

        $lines = (new Invoicer($tenant))->preview(CarbonImmutable::parse('2026-02-01'))['lines'];

        $this->assertStringContainsString('normaal € 330,00, speciale prijsafspraak', $lines[0]['description']);
        $this->assertSame(14900 * 12, $lines[0]['amount_cents']);
    }

    /**
     * Saving twice in a day -- first to Team, then agreeing a price for that
     * package -- produced two settlement lines with the same description and
     * opposite amounts. Together they were right, separately unreadable.
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
     * A change that is not a package change should still say what did change.
     * 'Abonnementswijziging' on its own explains nothing.
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
     * After a second change it should still say from which package to which.
     * Merging threw that away at first: all that was left was that something
     * had changed.
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

    /** A change that is reversed later on does not name a package change. */
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
     * Settlements from two different months do not belong on one heap.
     *
     * They cover a different number of days and a different invoice. Added
     * together they produce an amount that belongs to neither month, under a
     * description that describes neither.
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
     * Only one period is invoiced at a time. Skip a month and it never comes
     * back of its own accord, and revenue quietly disappears. That should at
     * the very least be visible.
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
     * Adding a module halfway through the month means paying for the days left
     * in that month. Not the whole month, and not nothing.
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
     * The normal price is the catalogue one, also for part of a month.
     * Pro-rating it produced an amount that exists nowhere: 'normaal EUR 18,00'
     * for a module that plainly costs EUR 22,50.
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

    /** A bundle counts from the day it became complete. */
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

    /**
     * The preview in the screen is the same pdf, but to show. With
     * 'attachment' the browser pushes it into the downloads folder and there is
     * nothing to see.
     */
    public function test_the_preview_shows_the_invoice_instead_of_downloading_it(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);
        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        $response = $this->actingAs($this->landlord(), 'landlord')
            ->get(route('landlord.invoice.preview', [$tenant->id, $invoice->id]))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('inline;', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_download_still_downloads(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);
        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        $response = $this->actingAs($this->landlord(), 'landlord')
            ->get(route('landlord.invoice.pdf', [$tenant->id, $invoice->id]))
            ->assertOk();

        $this->assertStringStartsWith('attachment;', $response->headers->get('content-disposition'));
    }

    public function test_a_stranger_cannot_look_at_an_invoice(): void
    {
        $tenant = $this->tenant(['subscription_started_on' => '2026-03-01']);
        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-03-01'));

        $this->get(route('landlord.invoice.preview', [$tenant->id, $invoice->id]))
            ->assertRedirect(route('landlord.login'));
    }
}
