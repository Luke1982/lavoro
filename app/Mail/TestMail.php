<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

class TestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** Marks the mail, so CopyMailToSentFolder leaves its copy to TechnicalManagementController. */
    public const HEADER = 'X-Test-Mail';

    public function build(): self
    {
        $this->withSymfonyMessage(function (Email $email) {
            $email->getHeaders()->addTextHeader(self::HEADER, '1');
        });

        return $this->subject('Test e-mail')
            ->html('<p>Dit is een test e-mail vanuit de technisch beheer module.</p>');
    }
}
