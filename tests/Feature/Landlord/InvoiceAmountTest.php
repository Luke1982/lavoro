<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\Invoice;
use Tests\TestCase;

/**
 * What appears on screen after creating has to be the amount the customer pays.
 *
 * The invoice knows three amounts: subtotal_cents (before discount),
 * total_cents (net, without VAT) and gross_cents (with VAT, and that is what
 * gets collected). The message showed total_cents -- 21% lower than what stood
 * below it in the list, and 21% lower than what leaves the account.
 */
class InvoiceAmountTest extends TestCase
{
    public function test_the_payable_amount_includes_vat(): void
    {
        $invoice = new Invoice([
            'subtotal_cents' => 2750,
            'total_cents' => 2750,
            'vat_percent' => 21,
            'vat_cents' => 578,
            'gross_cents' => 3328,
        ]);

        $this->assertSame(
            $invoice->total_cents + $invoice->vat_cents,
            $invoice->gross_cents,
            'gross_cents hoort netto plus btw te zijn; daar hangt de incasso aan.'
        );
    }

    /** The message after creating should name the amount to be paid. */
    public function test_the_controller_reports_the_gross_amount(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Landlord/InvoiceController.php'));

        $this->assertStringContainsString('Money::human($invoice->gross_cents)', $source);
        $this->assertStringNotContainsString('Money::human($invoice->total_cents)', $source);
    }
}
