<?php

namespace App\Mail;

use App\Mail\Concerns\AttachesFiles;
use App\Mail\Concerns\ShowsCompanyLogo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The customer's copy of a quote they signed: a short letter in the company's
 * own mail, with the signed PDF. A private customer is owed it on paper or by
 * mail, and anyone else is glad of it. No button: the link was used up by the
 * yes.
 */
class QuoteConfirmationMail extends Mailable
{
    use AttachesFiles;
    use Queueable;
    use SerializesModels;
    use ShowsCompanyLogo;

    /** @param array<int, array{name: string, data: string, mime: string}> $files */
    public function __construct(
        public string $rendered_subject,
        public string $body_html,
        public array $files,
    ) {}

    public function build(): self
    {
        $this->attachFiles($this->files);

        return $this->subject($this->rendered_subject)
            ->view('emails.access_link', [
                'body' => $this->body_html,
                'link_url' => null,
                'button_label' => $this->rendered_subject,
                'expires_on' => null,
                ...$this->companyLogo(),
            ]);
    }
}
