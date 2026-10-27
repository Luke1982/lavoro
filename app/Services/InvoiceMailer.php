<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Models\Central\Invoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends an invoice to the tenant. Always through the 'landlord' mailer and
 * never through the customer's: our invoices should come from us, also when the
 * customer has broken their own mail server.
 */
class InvoiceMailer
{
    public function send(Invoice $invoice, Tenant $tenant): bool
    {
        if (!filled($tenant->invoice_email)) {
            $invoice->forceFill(['mail_error' => 'Geen factuur-e-mailadres ingesteld'])->save();

            return false;
        }

        try {
            Mail::mailer('landlord')
                ->to($tenant->invoice_email)
                ->send(new InvoiceMail($invoice, $tenant));
        } catch (\Throwable $e) {
            /**
             * The invoice itself stays. Rolling it back because the mail did
             * not arrive would make the number disappear from a continuous
             * series.
             */
            Log::error('Sending the invoice failed', [
                'invoice' => $invoice->number,
                'tenant' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            $invoice->forceFill(['mail_error' => mb_substr($e->getMessage(), 0, 255)])->save();

            return false;
        }

        $invoice->forceFill(['mailed_at' => now(), 'mail_error' => null])->save();

        return true;
    }
}
