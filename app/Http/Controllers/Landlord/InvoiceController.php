<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\CreditInvoiceRequest;
use App\Http\Requests\Landlord\DestroyInvoiceRequest;
use App\Http\Requests\Landlord\ForgetPendingChargeRequest;
use App\Http\Requests\Landlord\IssueInvoiceRequest;
use App\Http\Requests\Landlord\MailInvoiceRequest;
use App\Models\Central\Invoice;
use App\Models\Central\PendingCharge;
use App\Models\Tenant;
use App\Services\EBoekhouden\EBoekhoudenClient;
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
        $starts_on = $tenant->subscription_started_on ? CarbonImmutable::parse($tenant->subscription_started_on) : null;

        /**
         * The preview is the invoice that comes next, not what could be made
         * today: before the start that is nothing, and the screen read € 0,00.
         */
        $next_on = $invoicer->nextInvoiceOn();
        $preview = $invoicer->preview($next_on);

        return inertia('Landlord/InvoicesPage', [
            'tenant' => $tenant,
            'invoices' => Invoice::on('central')->with(['lines', 'credits:id,number', 'creditedBy:id,number,credits_invoice_id'])
                ->where('tenant_id', $tenant->id)->latest('issued_on')->latest('id')->get(),
            'preview' => $preview,
            'is_due' => $invoicer->isDue(),
            'is_credit' => $preview['total_cents'] < 0,
            'next_invoice_on' => $next_on?->toDateString(),
            /** The one invoice that may be deleted: the newest in the system, if it is this customer's and nobody has it. */
            'deletable_id' => $this->deletableId($tenant, $invoicer),
            /** Only while it lies ahead: the screen then says when the first invoice comes. */
            'starts_on' => $starts_on?->isFuture() ? $starts_on->toDateString() : null,
            'eboekhouden' => [
                'configured' => app(EBoekhoudenClient::class)->configured(),
                'relation_code' => $tenant->eboekhouden_relation_code,
            ],
            /** Periods that were skipped or credited; those do not come back of their own accord. */
            'unbilled' => collect($invoicer->unbilledPeriods())
                ->map(fn (array $period) => [
                    'start' => $period['start']->toDateString(),
                    'label' => $period['start']->format('d-m-Y') . ' t/m ' . $period['end']->format('d-m-Y'),
                ])
                ->all(),
        ]);
    }

    private function deletableId(Tenant $tenant, Invoicer $invoicer): ?int
    {
        $newest = Invoice::on('central')->latest('id')->first();

        return $newest?->tenant_id === $tenant->id && $invoicer->canWithdraw($newest) ? $newest->id : null;
    }

    public function issueInvoice(IssueInvoiceRequest $request, string $id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $invoice = (new Invoicer($tenant))->issue(period: $request->period());

        /**
         * The amount that is on the invoice as well and that gets collected:
         * including VAT. total_cents is the net amount, and that used to be
         * here -- 21% lower than what the customer pays, right above a list
         * showing the amount with VAT.
         */
        return back()->with('status', "Factuur {$invoice->number} aangemaakt: € "
            . Money::human($invoice->gross_cents));
    }

    /** Deletes an invoice that should not have been made; Invoicer::withdraw says when that may. */
    public function destroyInvoice(DestroyInvoiceRequest $request, string $id, int $invoice_id)
    {
        [$tenant, $invoice] = $this->invoiceOf($id, $invoice_id);

        $next = (new Invoicer($tenant))->withdraw($invoice);

        return back()->with('status', "Factuur {$invoice->number} verwijderd; de volgende factuur wordt {$next}.");
    }

    /**
     * Cancels a wrong invoice with a credit note. After that the period is open
     * again for the invoice that should have been sent.
     */
    public function creditInvoice(CreditInvoiceRequest $request, string $id, int $invoice_id)
    {
        [$tenant, $invoice] = $this->invoiceOf($id, $invoice_id);

        $note = (new Invoicer($tenant))->credit($invoice);

        return back()->with('status', "Creditfactuur {$note->number} aangemaakt voor {$invoice->number}: € "
            . Money::human($note->gross_cents) . '. Wat die factuur verrekende staat weer open.');
    }

    /**
     * Takes an outstanding charge off before it is invoiced. A credit note puts
     * whatever the wrong invoice settled back in the queue; if the error was in
     * one of those charges, this is the way to keep it off the next invoice.
     */
    public function forgetCharge(ForgetPendingChargeRequest $request, string $id, int $charge_id)
    {
        $tenant = Tenant::on('central')->findOrFail($id);

        $charge = PendingCharge::on('central')
            ->where('tenant_id', $tenant->id)
            ->whereNull('invoice_id')
            ->findOrFail($charge_id);

        $charge->delete();

        return back()->with('status', "Verrekening verwijderd: {$charge->description}.");
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
