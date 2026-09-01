<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Central\Invoice;
use App\Models\Tenant;
use App\Services\InvoiceDocuments;
use App\Services\InvoiceMailer;
use App\Services\Invoicer;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Facturen van een klant: maken, versturen en downloaden.
 */
class InvoiceController extends Controller
{
    public function invoices(string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $invoicer = new Invoicer($tenant);
        [$start, $end] = $invoicer->periodFor(CarbonImmutable::now());

        return view('landlord.invoices', [
            'tenant' => $tenant,
            'invoices' => Invoice::on('central')->with('lines')
                ->where('tenant_id', $tenant->id)->latest('issued_on')->get(),
            'preview' => $invoicer->preview(),
            'is_due' => $invoicer->isDue(),
            'next_period_starts_on' => $end->addDay(),
        ]);
    }

    public function issueInvoice(string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $invoice = (new Invoicer($tenant))->issue();

        return back()->with('status', "Factuur {$invoice->number} aangemaakt: € "
            . Money::human($invoice->total_cents));
    }

    /** Handmatig: er hoort eerst iemand naar de factuur gekeken te hebben. */
    public function mailInvoice(string $id, int $invoice_id)
    {
        [$tenant, $invoice] = $this->invoiceOf($id, $invoice_id);

        if (!(new InvoiceMailer)->send($invoice, $tenant)) {
            return back()->with('error', 'Versturen mislukt: ' . $invoice->fresh()->mail_error);
        }

        return back()->with('status', "Factuur {$invoice->number} verstuurd naar {$tenant->invoice_email}.");
    }

    public function invoicePdf(string $id, int $invoice_id)
    {
        [$tenant, $invoice] = $this->invoiceOf($id, $invoice_id);
        $documents = new InvoiceDocuments($invoice, $tenant);

        return response($documents->pdf(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $documents->pdfName() . '"',
        ]);
    }

    public function invoiceXml(string $id, int $invoice_id)
    {
        [$tenant, $invoice] = $this->invoiceOf($id, $invoice_id);
        $documents = new InvoiceDocuments($invoice, $tenant);

        return response($documents->xml(), 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="' . $documents->xmlName() . '"',
        ]);
    }

    private function invoiceOf(string $id, int $invoice_id): array
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $invoice = Invoice::on('central')->with('lines')
            ->where('tenant_id', $tenant->id)->findOrFail($invoice_id);

        return [$tenant, $invoice];
    }
}
