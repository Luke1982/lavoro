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
            subject: 'Factuur ' . $this->invoice->number . ' van ' . (IssuerSetting::value('name', 'MajorLabel')),
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
     * De PDF is wat een mens leest, de UBL wat een boekhoudpakket inleest. Beide
     * mee: welke van de twee de klant gebruikt weten wij niet.
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
