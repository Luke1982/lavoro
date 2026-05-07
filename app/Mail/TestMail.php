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

    public function build(): self
    {
        $this->withSymfonyMessage(function (Email $email) {
            $email->getHeaders()->addTextHeader('X-Test-Mail', '1');
        });

        return $this->subject('Test e-mail')
            ->html('<p>Dit is een test e-mail vanuit de technisch beheer module.</p>');
    }
}
