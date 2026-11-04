<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\Invoice;
use App\Services\Invoicer;
use Carbon\CarbonImmutable;
use Tests\Concerns\MakesLandlordData;
use Tests\TestCase;

/**
 * Setting this year's numbering back to the last number that is still on an
 * invoice.
 *
 * Withdrawn invoices keep their number spent, so a series full of trial runs
 * starts the real invoices somewhere in the teens. A withdrawn invoice never
 * left the building -- mailed or collected cannot be withdrawn -- so its number
 * can be handed out again, as long as it is above every number that is still
 * on an invoice.
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
            $this->delete("/beheer/{$invoice->tenant_id}/facturen/{$invoice->id}")->assertRedirect();
        }

        $this->post('/beheer/facturatie/nummering')->assertRedirect()->assertSessionHas('status');

        $this->assertSame($this->sequence($kept) + 1, $this->sequence($this->issue()));
    }

    public function test_without_invoices_the_year_starts_at_one_again(): void
    {
        $invoice = $this->issue();
        $this->delete("/beheer/{$invoice->tenant_id}/facturen/{$invoice->id}");

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
        $this->delete("/beheer/{$withdrawn->tenant_id}/facturen/{$withdrawn->id}");

        $this->get('/beheer/catalogus')->assertInertia(fn ($page) => $page
            ->where('numbering.year', 2026)
            ->where('numbering.counter', $this->sequence($withdrawn))
            ->where('numbering.highest', $this->sequence($kept)));
    }
}
