<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\Invoice;
use App\Models\Central\PendingCharge;
use App\Models\Tenant;
use App\Services\InvoiceDocuments;
use App\Services\Invoicer;
use Carbon\CarbonImmutable;
use Tests\Concerns\MakesLandlordData;
use Tests\TestCase;

/**
 * An invoice that was wrong and has already gone out is cancelled with a credit
 * note: the same lines with the opposite sign, under a number of its own, and
 * naming the invoice it cancels. After that the period can be invoiced again,
 * correctly.
 */
class CreditInvoiceTest extends TestCase
{
    use MakesLandlordData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
        $this->actingAs($this->landlord(), 'landlord');
    }

    private function tenant(): Tenant
    {
        return $this->tenantRow(['subscription_started_on' => '2026-11-01']);
    }

    /** Credited once it has gone out; before that it is deleted instead. */
    private function credit(Invoice $invoice)
    {
        $invoice->update(['mailed_at' => $invoice->mailed_at ?? now()]);

        return $this->post("/beheer/{$invoice->tenant_id}/facturen/{$invoice->id}/crediteren");
    }

    private function creditNoteOf(Invoice $invoice): ?Invoice
    {
        return Invoice::on('central')->with('lines')->where('credits_invoice_id', $invoice->id)->first();
    }

    public function test_a_credit_note_reverses_every_amount_under_a_number_of_its_own(): void
    {
        $tenant = $this->tenant();
        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id, 'kind' => 'topup', 'description' => 'AI-tegoed', 'amount_cents' => 2500,
        ]);
        $invoice = (new Invoicer($tenant))->issue();

        $this->credit($invoice)->assertRedirect()->assertSessionHas('status');

        $note = $this->creditNoteOf($invoice);

        $this->assertNotNull($note);
        $this->assertNotSame($invoice->number, $note->number);
        $this->assertSame(-$invoice->subtotal_cents, $note->subtotal_cents);
        $this->assertSame(-$invoice->total_cents, $note->total_cents);
        $this->assertSame(-$invoice->vat_cents, $note->vat_cents);
        $this->assertSame(-$invoice->gross_cents, $note->gross_cents);
        $this->assertSame(
            $invoice->lines->map(fn ($line) => [$line->description, $line->kind, -$line->amount_cents])->all(),
            $note->lines->map(fn ($line) => [$line->description, $line->kind, $line->amount_cents])->all(),
        );
        $this->assertSame($invoice->period_start->toDateString(), $note->period_start->toDateString());
    }

    public function test_the_yearly_discount_is_reversed_too(): void
    {
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-11-01', 'billing_period' => 'yearly']);
        $invoice = (new Invoicer($tenant))->issue();

        $this->assertGreaterThan(0, $invoice->discount_cents);

        $this->credit($invoice);

        $note = $this->creditNoteOf($invoice);
        $this->assertSame(-$invoice->discount_cents, $note->discount_cents);
        $this->assertSame($note->subtotal_cents - $note->discount_cents, $note->total_cents);
    }

    /** Once credited, the period is open again, so the right invoice can follow. */
    public function test_a_credited_period_can_be_invoiced_again(): void
    {
        $tenant = $this->tenant();
        $invoice = (new Invoicer($tenant))->issue();

        $this->assertFalse((new Invoicer($tenant))->isDue());

        $this->credit($invoice);

        $this->assertTrue((new Invoicer($tenant))->isDue());
        $this->assertSame($invoice->total_cents, (new Invoicer($tenant))->issue()->total_cents);
    }

    /** What the wrong invoice settled is outstanding again, for the right one. */
    public function test_the_charges_it_settled_come_back(): void
    {
        $tenant = $this->tenant();
        PendingCharge::on('central')->create([
            'tenant_id' => $tenant->id, 'kind' => 'topup', 'description' => 'AI-tegoed', 'amount_cents' => 2500,
        ]);
        $invoice = (new Invoicer($tenant))->issue();

        $this->credit($invoice);

        $this->assertCount(1, (new Invoicer($tenant))->pendingCharges());
    }

    public function test_an_invoice_is_credited_only_once(): void
    {
        $invoice = (new Invoicer($this->tenant()))->issue();

        $this->credit($invoice);
        $this->credit($invoice)->assertSessionHas('error');

        $this->assertSame(1, Invoice::on('central')->where('credits_invoice_id', $invoice->id)->count());
    }

    public function test_a_credit_note_is_not_credited_itself(): void
    {
        $invoice = (new Invoicer($this->tenant()))->issue();
        $this->credit($invoice);

        $this->credit($this->creditNoteOf($invoice))->assertSessionHas('error');
    }

    /**
     * The credit note is final, and so is the invoice it cancels: deleting
     * either leaves the other pointing at nothing.
     */
    public function test_neither_side_of_a_credit_can_be_deleted(): void
    {
        $invoice = (new Invoicer($this->tenant()))->issue();
        $this->credit($invoice);
        $note = $this->creditNoteOf($invoice);

        $this->delete("/beheer/{$invoice->tenant_id}/facturen/{$invoice->id}")->assertSessionHas('error');
        $this->delete("/beheer/{$note->tenant_id}/facturen/{$note->id}")->assertSessionHas('error');

        $this->assertNotNull(Invoice::on('central')->find($invoice->id));
        $this->assertNotNull(Invoice::on('central')->find($note->id));
    }

    /** A cancelled invoice is not collected: the customer owes nothing for it. */
    public function test_a_credited_invoice_is_left_out_of_the_direct_debit_batch(): void
    {
        $tenant = $this->tenantRow([
            'subscription_started_on' => '2026-11-01',
            'payment_method' => 'direct_debit',
            'iban' => 'NL91ABNA0417164300',
            'mandate_reference' => 'MND-1',
            'mandate_signed_on' => '2026-01-01',
        ]);
        $invoice = (new Invoicer($tenant))->issue();
        $invoice->update(['mailed_at' => now()]);
        $this->credit($invoice);
        $corrected = (new Invoicer($tenant))->issue();

        $this->get(route('landlord.collections'))->assertInertia(function ($page) use ($invoice, $corrected) {
            $numbers = collect($page->toArray()['props']['invoices'])->pluck('number');

            $this->assertFalse($numbers->contains($invoice->number), 'de gecrediteerde factuur hoort er niet bij');
            $this->assertTrue($numbers->contains($corrected->number), 'de juiste factuur wel');
        });
    }

    /** A credit note has to say which invoice it cancels, on paper and in the UBL. */
    public function test_the_documents_name_the_invoice_that_is_credited(): void
    {
        $tenant = $this->tenant();
        $invoice = (new Invoicer($tenant))->issue();
        $this->credit($invoice);
        $note = $this->creditNoteOf($invoice);

        $html = view('landlord.invoice.pdf', [
            'invoice' => $note, 'tenant' => $tenant, 'issuer' => [], 'logo' => null, 'script_font' => '',
        ])->render();

        $this->assertStringContainsString('Creditfactuur', $html);
        $this->assertStringContainsString('factuur ' . $invoice->number, $html);

        $xml = (new InvoiceDocuments($note, $tenant))->xml();

        $this->assertStringContainsString('<cac:BillingReference>', $xml);
        $this->assertStringContainsString('<cbc:ID>' . $invoice->number . '</cbc:ID>', $xml);
    }

    public function test_an_invoice_that_has_not_gone_out_is_deleted_and_not_credited(): void
    {
        $invoice = (new Invoicer($this->tenant()))->issue();

        $this->post("/beheer/{$invoice->tenant_id}/facturen/{$invoice->id}/crediteren")->assertSessionHas('error');

        $this->assertNull($this->creditNoteOf($invoice));
    }

    /** The refund for unused days was worked out against the invoice that is now cancelled. */
    public function test_a_cancellation_is_not_refunded_on_top_of_a_credited_invoice(): void
    {
        $tenant = $this->tenant();
        $invoice = (new Invoicer($tenant))->issue();
        $tenant->update(['subscription_ends_on' => '2026-11-15']);
        (new Invoicer($tenant))->settleCancellation(CarbonImmutable::parse('2026-11-15'));

        $this->credit($invoice);

        $this->assertSame(1375, (new Invoicer($tenant))->issue()->total_cents);
    }

    /** An upgrade after the invoice is worked out again, over the days spent on the old package. */
    public function test_a_package_change_after_the_credited_invoice_is_settled_again(): void
    {
        $tenant = $this->tenant();
        $invoice = (new Invoicer($tenant))->issue();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        $tenant->update(['package_key' => 'team']);
        (new Invoicer($tenant))->prorate(2750, 8750, old_package: 'Starter', new_package: 'Team');

        $this->credit($invoice);

        $this->assertSame(1375 + 4375, (new Invoicer($tenant))->issue()->total_cents);
    }

    /**
     * The upgrade of 16 November was charged on December's invoice. Crediting
     * November takes it back, and November is worked out again: half a month
     * on each package, across the two invoices.
     */
    public function test_a_settlement_a_later_invoice_charged_is_reversed_with_the_credit(): void
    {
        $tenant = $this->tenant();
        $november = (new Invoicer($tenant))->issue();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));
        $tenant->update(['package_key' => 'team']);
        (new Invoicer($tenant))->prorate(2750, 8750, old_package: 'Starter', new_package: 'Team');
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00'));
        $december = (new Invoicer($tenant))->issue();

        $this->credit($november);
        $again = (new Invoicer($tenant))->issue(period: CarbonImmutable::parse('2026-11-01'));

        $this->assertSame(8750 + 3000, $december->total_cents);
        $this->assertSame(1375 + 4375, 3000 + $again->total_cents);
    }

    /** A credited month in the past is open again, and invoiced from the list of open periods. */
    public function test_a_credited_period_in_the_past_is_invoiced_again_from_the_panel(): void
    {
        $tenant = $this->tenantRow(['subscription_started_on' => '2026-10-01']);
        $october = (new Invoicer($tenant))->issue(CarbonImmutable::parse('2026-10-01'));
        (new Invoicer($tenant))->issue();
        $this->credit($october);

        $this->get(route('landlord.invoices', $tenant->id))
            ->assertInertia(fn ($page) => $page->where('unbilled.0.start', '2026-10-01'));

        $this->post("/beheer/{$tenant->id}/facturen", ['period_start' => '2026-10-01'])->assertSessionHas('status');

        $again = Invoice::on('central')->where('tenant_id', $tenant->id)->latest('id')->first();
        $this->assertSame('2026-10-01', $again->period_start->toDateString());
        $this->assertSame('2026-11-04', $again->issued_on->toDateString());

        $this->post("/beheer/{$tenant->id}/facturen", ['period_start' => '2026-10-01'])->assertSessionHasErrors('period_start');
    }
}
