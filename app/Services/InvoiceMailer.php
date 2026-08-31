<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Models\Central\Invoice;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Verstuurt een factuur naar de tenant. Altijd via de mailer 'landlord' en
 * nooit via die van de klant: onze facturen horen van ons te komen, ook als
 * de klant zijn eigen mailserver stuk heeft.
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
             * De factuur zelf blijft staan. Hem terugdraaien omdat de mail niet
             * aankwam zou het nummer laten verdwijnen uit een doorlopende reeks.
             */
            Log::error('Factuur versturen mislukt', [
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
