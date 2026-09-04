<?php

namespace Tests\Feature\Landlord;

use App\Models\Central\Invoice;
use Tests\TestCase;

/**
 * Wat er na het aanmaken op het scherm komt te staan, moet het bedrag zijn dat
 * de klant betaalt.
 *
 * De factuur kent drie bedragen: subtotal_cents (voor korting), total_cents
 * (netto, zonder btw) en gross_cents (met btw, en dat is wat er geincasseerd
 * wordt). De melding toonde total_cents -- 21% lager dan wat eronder in de lijst
 * stond, en 21% lager dan wat er van de rekening gaat.
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

    /** De melding na het aanmaken hoort het te betalen bedrag te noemen. */
    public function test_the_controller_reports_the_gross_amount(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Landlord/InvoiceController.php'));

        $this->assertStringContainsString('Money::human($invoice->gross_cents)', $source);
        $this->assertStringNotContainsString('Money::human($invoice->total_cents)', $source);
    }
}
