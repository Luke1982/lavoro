<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\Invoice;
use App\Models\Central\PendingCharge;
use App\Services\Invoicer;
use Carbon\CarbonImmutable;
use Tests\Concerns\MakesLandlordData;
use Tests\TestCase;

/**
 * An invoice that should not have been made can go: only the newest one in the
 * whole system, and only while nobody has it. Its number goes back, so the
 * series has no gaps. Anything older is corrected with a credit note.
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

    public function test_the_number_goes_to_the_next_invoice(): void
    {
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-02-01']);
        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-02-01'));

        $this->delete("/beheer/{$tenant->id}/facturen/{$invoice->id}");

        $other = $this->tenantRow(['subscription_started_on' => '2026-02-01']);
        $next = (new Invoicer($other))->issue(CarbonImmutable::parse('2026-02-01'));

        $this->assertSame($invoice->number, $next->number);
    }

    /**
     * Another customer was invoiced after it. Deleting it now would leave a
     * gap, so it stays, and the way back is a credit note -- also when it was
     * never sent.
     */
    public function test_an_invoice_with_a_newer_one_after_it_is_credited_not_deleted(): void
    {
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-02-01']);
        $invoice = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-02-01'));
        $other = $this->tenantRow(['subscription_started_on' => '2026-02-01']);
        (new Invoicer($other))->issue(CarbonImmutable::parse('2026-02-01'));

        $this->get(route('landlord.invoices', $tenant->id))->assertInertia(fn ($page) => $page->where('deletable_id', null));

        $this->delete("/beheer/{$tenant->id}/facturen/{$invoice->id}")
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'gat in de nummering'));
        $this->assertNotNull(Invoice::on('central')->find($invoice->id));

        $this->post("/beheer/{$tenant->id}/facturen/{$invoice->id}/crediteren")->assertSessionHas('status');
        $this->assertTrue(Invoice::on('central')->where('credits_invoice_id', $invoice->id)->exists());
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

    /** The upgrade after the deleted invoice was worked out against it; deleting works it out again. */
    public function test_a_package_change_after_the_deleted_invoice_is_settled_again(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-11-01']);
        $invoice = (new Invoicer($tenant))->issue();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        $tenant->update(['package_key' => 'team']);
        (new Invoicer($tenant))->prorate(2750, 8750, old_package: 'Starter', new_package: 'Team');

        $this->delete("/beheer/{$tenant->id}/facturen/{$invoice->id}")->assertSessionHas('status');

        $this->assertSame(1375 + 4375, (new Invoicer($tenant))->issue()->total_cents);
    }

    /**
     * An invoice for topped up credit alone changes nothing about the period:
     * the month is still invoiced, and the refund for a cancellation in it
     * stays.
     */
    public function test_deleting_an_invoice_for_credit_alone_keeps_the_period_as_it_was(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-11-01']);
        (new Invoicer($tenant))->issue()->update(['mailed_at' => now()]);
        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id, 'kind' => 'topup', 'description' => 'AI-tegoed', 'amount_cents' => 2500,
        ]);
        $topup = (new Invoicer($tenant))->issue();
        $tenant->update(['subscription_ends_on' => '2026-11-15']);
        (new Invoicer($tenant))->settleCancellation(CarbonImmutable::parse('2026-11-15'));

        $this->delete("/beheer/{$tenant->id}/facturen/{$topup->id}")->assertSessionHas('status');

        $this->assertEqualsCanonicalizing([2500, -1375], (new Invoicer($tenant))->pendingCharges()->pluck('amount_cents')->all());
    }
}
