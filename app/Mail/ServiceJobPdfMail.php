<?php

namespace App\Mail;

use App\Models\ServiceJob;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ServiceJobPdfMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public ServiceJob $serviceJob, private string $pdfBinary)
    {
        //
    }

    public function build(): self
    {
        $filename = 'keuring-' . $this->serviceJob->id . '.pdf';

        return $this->subject('Keuring #' . $this->serviceJob->id)
            ->view('emails.servicejob.pdf_html', [
                'serviceJob' => $this->serviceJob,
            ])
            ->attachData($this->pdfBinary, $filename, [
                'mime' => 'application/pdf',
            ]);
    }
}
