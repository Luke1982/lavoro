<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\Invoice;
use App\Models\Central\PendingCharge;
use App\Services\InvoiceNumbers;
use App\Services\Invoicer;
use Carbon\CarbonImmutable;
use Tests\Concerns\MakesLandlordData;
use Tests\TestCase;

/**
 * Resetting the numbering of a year to the highest number still on an invoice.
 *
 * Deleting the newest invoice gives its number back by itself. Before that
 * rule, a withdrawn invoice kept its number spent, so a year can still end in
 * a gap; this closes it. The tests make such a gap the old way: by deleting
 * the invoice without handing its number back.
 */
class ResetInvoiceNumbersTest extends TestCase
{
    use MakesLandlordData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
        $this->actingAs($this->landlord(), 'landlord');
    }

    private function issue(): Invoice
    {
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-11-01']);

        return (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-11-04'));
    }

    private function sequence(Invoice $invoice): int
    {
        return (int) str_replace('2026-LVR-', '', $invoice->number);
    }

    public function test_the_numbers_of_withdrawn_invoices_are_handed_out_again(): void
    {
        $kept = $this->issue();
        $withdrawn = [$this->issue(), $this->issue()];

        foreach ($withdrawn as $invoice) {
            $invoice->delete();
        }

        $this->post('/beheer/facturatie/nummering')->assertRedirect()->assertSessionHas('status');

        $this->assertSame($this->sequence($kept) + 1, $this->sequence($this->issue()));
    }

    public function test_without_invoices_the_year_starts_at_one_again(): void
    {
        $this->issue()->delete();

        $this->post('/beheer/facturatie/nummering')->assertRedirect();

        $this->assertSame('2026-LVR-1', $this->issue()->number);
    }

    /** Nothing above the last invoice to give back: there is nothing to reset. */
    public function test_a_series_without_gaps_at_the_end_is_left_alone(): void
    {
        $last = $this->issue();

        $this->post('/beheer/facturatie/nummering')->assertSessionHas('error');

        $this->assertSame($this->sequence($last) + 1, $this->sequence($this->issue()));
    }

    public function test_the_catalogue_shows_where_the_numbering_stands(): void
    {
        $kept = $this->issue();
        $withdrawn = $this->issue();
        $withdrawn->delete();

        $this->get('/beheer/catalogus')->assertInertia(fn ($page) => $page
            ->where('numbering.year', 2026)
            ->where('numbering.counter', $this->sequence($withdrawn))
            ->where('numbering.highest', $this->sequence($kept)));
    }

    /**
     * Starting the year over: every invoice of the year goes, credit notes and
     * sent ones too, and the next one is number 1. What they settled is
     * outstanding again; last year is left alone.
     */
    public function test_starting_the_year_over_deletes_its_invoices_and_starts_at_one(): void
    {
        $last_year = (new Invoicer($this->tenantRow(['subscription_started_on' => '2025-12-01'])))
            ->issue(CarbonImmutable::parse('2025-12-01'));
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-11-01']);
        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id, 'kind' => 'topup', 'description' => 'AI-tegoed', 'amount_cents' => 2500,
        ]);
        $sent = (new Invoicer($tenant))->issue();
        $sent->update(['mailed_at' => now()]);
        (new Invoicer($tenant))->credit($sent);
        $this->issue();

        $this->get('/beheer/catalogus')->assertInertia(fn ($page) => $page
            ->where('numbering.issued', 3)
            ->where('numbering.mailed', 1));

        $this->post('/beheer/facturatie/opnieuw-beginnen', ['confirm_year' => '2026'])->assertSessionHas('status');

        $this->assertSame(0, (new InvoiceNumbers)->ofYear(2026)->count());
        $this->assertNotNull(Invoice::on('central')->find($last_year->id));
        $this->assertCount(1, (new Invoicer($tenant))->pendingCharges());
        $this->assertSame('2026-LVR-1', $this->issue()->number);
    }

    public function test_the_year_has_to_be_typed_over(): void
    {
        $invoice = $this->issue();

        $this->post('/beheer/facturatie/opnieuw-beginnen', ['confirm_year' => '2025'])->assertSessionHasErrors('confirm_year');

        $this->assertNotNull(Invoice::on('central')->find($invoice->id));
    }

    /** The upgrade after November's invoice was worked out against it; after the restart, against nothing. */
    public function test_settlements_are_worked_out_again_when_the_year_starts_over(): void
    {
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-11-01']);
        (new Invoicer($tenant))->issue();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        $tenant->update(['package_key' => 'team']);
        (new Invoicer($tenant))->prorate(2750, 8750, old_package: 'Starter', new_package: 'Team');

        $this->post('/beheer/facturatie/opnieuw-beginnen', ['confirm_year' => '2026'])->assertSessionHas('status');

        $this->assertSame(1375 + 4375, (new Invoicer($tenant))->issue()->total_cents);
    }

    /** A credit note numbered in the next year cannot lose the invoice it cancels. */
    public function test_an_invoice_credited_from_another_year_stops_the_restart(): void
    {
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-11-01']);
        $invoice = (new Invoicer($tenant))->issue();
        $invoice->update(['mailed_at' => now()]);
        $this->travelTo(CarbonImmutable::parse('2027-01-02 10:00:00'));
        (new Invoicer($tenant))->credit($invoice);
        $this->travelTo(CarbonImmutable::parse('2026-12-31 10:00:00'));

        $this->post('/beheer/facturatie/opnieuw-beginnen', ['confirm_year' => '2026'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'ander jaar'));

        $this->assertNotNull(Invoice::on('central')->find($invoice->id));
        $this->assertSame(1, (new InvoiceNumbers)->counter(2026));
    }

    /** In January, crediting a December invoice and then starting January over. */
    public function test_a_credit_note_for_last_years_invoice_stops_the_restart(): void
    {
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-11-01']);
        $invoice = (new Invoicer($tenant))->issue();
        $invoice->update(['mailed_at' => now()]);
        $this->travelTo(CarbonImmutable::parse('2027-01-05 10:00:00'));
        (new Invoicer($tenant))->credit($invoice);

        $this->post('/beheer/facturatie/opnieuw-beginnen', ['confirm_year' => '2027'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'ander jaar'));

        $this->assertTrue(Invoice::on('central')->where('credits_invoice_id', $invoice->id)->exists());
    }

    /**
     * October invoiced, upgraded on the 16th (settled on November's invoice),
     * October credited and invoiced again, then the year started over. The
     * upgrade is one change, worked out once: 15 days Starter, 16 days Team.
     */
    public function test_a_change_settled_again_after_a_credit_is_settled_once_after_a_restart(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00'));
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-10-01']);
        $october = (new Invoicer($tenant))->issue();
        $october->update(['mailed_at' => now()]);
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00:00'));
        $tenant->update(['package_key' => 'team']);
        (new Invoicer($tenant))->prorate(2750, 8750, old_package: 'Starter', new_package: 'Team');
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00'));
        (new Invoicer($tenant))->issue()->update(['mailed_at' => now()]);
        (new Invoicer($tenant))->credit($october);
        (new Invoicer($tenant))->issue(period: CarbonImmutable::parse('2026-10-01'));

        $this->post('/beheer/facturatie/opnieuw-beginnen', ['confirm_year' => '2026'])->assertSessionHas('status');

        $again = (new Invoicer($tenant))->issue(period: CarbonImmutable::parse('2026-10-01'));
        $this->assertSame((int) round(2750 * 15 / 31) + (int) round(8750 * 16 / 31), $again->total_cents);
    }
}
