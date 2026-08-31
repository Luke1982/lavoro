<?php

namespace App\Services;

use App\Models\Central\Invoice;
use App\Models\Central\IssuerSetting;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Eén plek die van een factuur een PDF en een UBL-bestand maakt, zodat de
 * knop in het beheer, de mail en de test hetzelfde bestand opleveren.
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
     * Als data-URI en niet als pad: dompdf haalt een bestand alleen op als het
     * dat mag, en een factuur die stil zonder logo uitrolt is lastiger te
     * merken dan een die niet rendert.
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
