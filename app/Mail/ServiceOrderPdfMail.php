<?php

namespace App\Mail;

use App\Mail\Concerns\ShowsCompanyLogo;
use App\Models\ServiceOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ServiceOrderPdfMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use ShowsCompanyLogo;

    public function __construct(public ServiceOrder $serviceOrder, private string $pdfBinary)
    {
        //
    }

    public function build(): self
    {
        $filename = 'werkbon-' . $this->serviceOrder->id . '.pdf';

        return $this->subject('Werkbon #' . $this->serviceOrder->id)
            ->view('emails.serviceorder.pdf_html', [
                'serviceOrder' => $this->serviceOrder,
                ...$this->companyLogo(),
            ])
            ->attachData($this->pdfBinary, $filename, [
                'mime' => 'application/pdf',
            ]);
    }
}
