<?php

namespace App\Mail;

use App\Models\ServiceOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ServiceOrderWithJobsPdfMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public ServiceOrder $serviceOrder, private string $orderPdf, private array $jobPdfs)
    {
    }

    public function build(): self
    {
        $mail = $this->subject('Werkbon #' . $this->serviceOrder->id . ' + keuringen')
            ->view('emails.serviceorder.pdf_with_jobs_html', [
                'serviceOrder' => $this->serviceOrder,
            ])
            ->attachData($this->orderPdf, 'werkbon-' . $this->serviceOrder->id . '.pdf', [
                'mime' => 'application/pdf',
            ]);
        foreach ($this->jobPdfs as $jobId => $bin) {
            $mail->attachData($bin, 'keuring-' . $jobId . '.pdf', [
                'mime' => 'application/pdf',
            ]);
        }
        return $mail;
    }
}
