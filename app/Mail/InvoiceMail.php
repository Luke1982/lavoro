<?php

namespace App\Mail;

use App\Models\Central\Invoice;
use App\Models\Central\IssuerSetting;
use App\Models\Tenant;
use App\Services\InvoiceDocuments;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice, public Tenant $tenant) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->invoice->gross_cents < 0 ? 'Creditfactuur ' : 'Factuur ') . $this->invoice->number
                . ' van ' . IssuerSetting::value('name', 'MajorLabel'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'landlord.invoice.mail',
            with: [
                'invoice' => $this->invoice,
                'tenant' => $this->tenant,
                'issuer' => IssuerSetting::all_values(),
            ],
        );
    }

    /**
     * The PDF is what a person reads, the UBL what an accounting package
     * imports. Both along: which of the two the customer uses we do not know.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $documents = new InvoiceDocuments($this->invoice, $this->tenant);

        return [
            Attachment::fromData(fn () => $documents->pdf(), $documents->pdfName())
                ->withMime('application/pdf'),
            Attachment::fromData(fn () => $documents->xml(), $documents->xmlName())
                ->withMime('application/xml'),
        ];
    }
}
