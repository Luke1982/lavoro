<?php

namespace App\Mail;

use App\Mail\Concerns\ShowsCompanyLogo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The request for more information, with the button to the upload page below.
 *
 * The link is here and not in the text the colleague types: that text goes
 * through an editor, and a link that can be edited is a link that can break.
 *
 * A copy in the Sent folder comes by itself: CopyMailToSentFolder listens to
 * every mail sent and puts it back over IMAP, and with Graph the mailbox does
 * that itself.
 */
class TicketInfoRequestMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use ShowsCompanyLogo;

    public function __construct(
        public string $rendered_subject,
        public string $body_html,
        public string $upload_url,
        public ?string $expires_on = null,
    ) {}

    public function build(): self
    {
        return $this->subject($this->rendered_subject)
            ->view('emails.ticket_info_request', [
                'body' => $this->body_html,
                'upload_url' => $this->upload_url,
                'expires_on' => $this->expires_on,
                ...$this->companyLogo(),
            ]);
    }
}
