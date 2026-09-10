<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\Invoice;
use App\Models\Central\PendingCharge;
use App\Services\Invoicer;
use Carbon\CarbonImmutable;
use Tests\Concerns\MakesLandlordData;
use Tests\TestCase;

/**
 * An invoice that should not have been made can go, as long as it has not left
 * the building. What does not go is its number: the next invoice takes the
 * following one.
 *
 * A series with a gap is a question you can answer. Two invoices carrying the
 * same number is not, and that is what happened when the next number was the
 * highest one in the table plus one.
 */
class WithdrawInvoiceTest extends TestCase
{
    use MakesLandlordData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->landlord(), 'landlord');
    }

    public function test_an_invoice_that_has_not_been_sent_can_be_withdrawn(): void
    {
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-02-01']);
        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-02-01'));

        $this->delete("/beheer/{$tenant->id}/facturen/{$invoice->id}")
            ->assertRedirect();

        $this->assertNull(Invoice::on('central')->find($invoice->id));
    }

    public function test_the_number_stays_spent(): void
    {
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-02-01']);
        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-02-01'));

        $this->delete("/beheer/{$tenant->id}/facturen/{$invoice->id}");

        $other = $this->tenantRow(['subscription_started_on' => '2026-02-01']);
        $next = (new Invoicer($other))->issue(CarbonImmutable::parse('2026-02-01'));

        $this->assertNotSame($invoice->number, $next->number);
    }

    /** Whatever it settled is outstanding again, so nothing is lost with it. */
    public function test_the_charges_it_settled_come_back(): void
    {
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-02-01']);

        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id,
            'kind' => 'topup',
            'description' => 'AI-tegoed',
            'amount_cents' => 2500,
        ]);

        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-02-01'));

        $this->assertSame(0, PendingCharge::on('central')
            ->where('tenant_id', $tenant->id)->whereNull('invoice_id')->count());

        $this->delete("/beheer/{$tenant->id}/facturen/{$invoice->id}");

        $this->assertSame(1, PendingCharge::on('central')
            ->where('tenant_id', $tenant->id)->whereNull('invoice_id')->count());
    }

    /** Once the customer has it, the way back is a credit note. */
    public function test_a_sent_invoice_is_not_deleted(): void
    {
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-02-01']);
        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-02-01'));

        $invoice->update(['mailed_at' => now()]);

        $this->delete("/beheer/{$tenant->id}/facturen/{$invoice->id}");

        $this->assertNotNull(Invoice::on('central')->find($invoice->id));
    }
}
