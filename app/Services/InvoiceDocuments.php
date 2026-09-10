<?php

namespace App\Services;

use App\Models\Central\Invoice;
use App\Models\Central\IssuerSetting;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * One place that turns an invoice into a PDF and a UBL file, so the button in
 * the admin panel, the mail and the test all produce the same file.
 */
class InvoiceDocuments
{
    public function __construct(private Invoice $invoice, private Tenant $tenant) {}

    public function pdf(): string
    {
        return Pdf::loadView('landlord.invoice.pdf', [
            'invoice' => $this->invoice->loadMissing('lines'),
            'tenant' => $this->tenant,
            'issuer' => IssuerSetting::all_values(),
            'logo' => $this->logo(),
            'script_font' => 'file://' . resource_path('fonts/DancingScript.ttf'),
        ])->output();
    }

    public function xml(): string
    {
        return (new InvoiceUbl($this->invoice, $this->tenant))->toXml();
    }

    public function pdfName(): string
    {
        return $this->invoice->number . '.pdf';
    }

    public function xmlName(): string
    {
        return $this->invoice->number . '.xml';
    }

    /**
     * As a data URI and not as a path: dompdf only fetches a file when it may,
     * and an invoice that quietly comes out without a logo is harder to notice
     * than one that does not render.
     */
    public function logo(): ?string
    {
        $file = public_path('img/majorlabel-logo.jpg');

        if (!is_readable($file)) {
            return null;
        }

        return 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($file));
    }
}
