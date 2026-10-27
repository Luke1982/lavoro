<?php

namespace App\Http\Controllers\Landlord;

use App\Exceptions\Refusal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\DestroyInvoiceRequest;
use App\Http\Requests\Landlord\IssueInvoiceRequest;
use App\Http\Requests\Landlord\MailInvoiceRequest;
use App\Models\Central\Invoice;
use App\Models\Central\PendingCharge;
use App\Models\Tenant;
use App\Services\InvoiceDocuments;
use App\Services\InvoiceMailer;
use App\Services\Invoicer;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A customer's invoices: creating, sending and downloading.
 */
class InvoiceController extends Controller
{
    public function invoices(string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $invoicer = new Invoicer($tenant);
        [$start, $end] = $invoicer->periodFor(CarbonImmutable::now());

        return inertia('Landlord/InvoicesPage', [
            'tenant' => $tenant,
            'invoices' => Invoice::on('central')->with('lines')
                ->where('tenant_id', $tenant->id)->latest('issued_on')->get(),
            'preview' => $invoicer->preview(),
            'is_due' => $invoicer->isDue(),
            'is_credit' => $invoicer->isCreditNote(),
            'next_period_starts_on' => $end->addDay(),
            /** Months that were skipped; those do not come back of their own accord. */
            'unbilled' => collect($invoicer->unbilledPeriods())
                ->map(fn (array $period) => $period['start']->format('d-m-Y') . ' t/m ' . $period['end']->format('d-m-Y'))
                ->all(),
        ]);
    }

    public function issueInvoice(IssueInvoiceRequest $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $invoice = (new Invoicer($tenant))->issue();

        /**
         * The amount that is on the invoice as well and that gets collected:
         * including VAT. total_cents is the net amount, and that used to be
         * here -- 21% lower than what the customer pays, right above a list
         * showing the amount with VAT.
         */
        return back()->with('status', "Factuur {$invoice->number} aangemaakt: € "
            . Money::human($invoice->gross_cents));
    }

    /**
     * Withdraws an invoice that should not have been made.
     *
     * Only while it has not left the building: once it has been mailed or has
     * travelled along in a collection file the customer has it, and then the way
     * back is a credit note and not a delete.
     *
     * The number does not come back. It stays spent in the counter, so the next
     * invoice takes the following one -- a series with a gap is a question you
     * can answer, two invoices with the same number is not.
     */
    public function destroyInvoice(DestroyInvoiceRequest $request, string $id, int $invoice_id)
    {
        [$tenant, $invoice] = $this->invoiceOf($id, $invoice_id);

        if ($invoice->mailed_at || $invoice->collected_at) {
            throw new Refusal("Factuur {$invoice->number} is al de deur uit."
                . ' Terugdraaien gaat met een creditfactuur, niet met verwijderen.');
        }

        $number = $invoice->number;

        /** The charges it settled become outstanding again, for the next invoice. */
        PendingCharge::on('central')->where('invoice_id', $invoice->id)->update(['invoice_id' => null]);

        $invoice->delete();

        return back()->with('status', "Factuur {$number} verwijderd."
            . ' Het nummer blijft vergeven; de volgende factuur krijgt het daaropvolgende.');
    }

    /** By hand: someone should have looked at the invoice first. */
    public function mailInvoice(MailInvoiceRequest $request, string $id, int $invoice_id)
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

    /**
     * The same pdf, but to show instead of to download.
     *
     * The difference is only in Content-Disposition: with 'attachment' the
     * browser pushes it into the downloads folder and there is nothing to look
     * at, so a preview in the screen needs an address of its own.
     */
    public function invoicePreview(string $id, int $invoice_id)
    {
        [$tenant, $invoice] = $this->invoiceOf($id, $invoice_id);
        $documents = new InvoiceDocuments($invoice, $tenant);

        return response($documents->pdf(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $documents->pdfName() . '"',
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
