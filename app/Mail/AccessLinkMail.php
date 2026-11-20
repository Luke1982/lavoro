<?php

namespace App\Mail;

use App\Mail\Concerns\AttachesFiles;
use App\Mail\Concerns\ShowsCompanyLogo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A text a colleague wrote, with a button below it to a link for someone
 * without an account: a storing to add information to, a quote to answer.
 *
 * The link is here and not in the text the colleague types: that text goes
 * through an editor, and a link that can be edited is a link that can break.
 *
 * Files go along when the text is about them, such as the quote on paper and
 * the terms it is offered under.
 *
 * A copy in the Sent folder comes by itself: CopyMailToSentFolder listens to
 * every mail sent and puts it back over IMAP, and with Graph the mailbox does
 * that itself.
 */
class AccessLinkMail extends Mailable
{
    use AttachesFiles;
    use Queueable;
    use SerializesModels;
    use ShowsCompanyLogo;

    /** @param array<int, array{name: string, data: string, mime: string}> $files */
    public function __construct(
        public string $rendered_subject,
        public string $body_html,
        public string $link_url,
        public string $button_label,
        public ?string $expires_on = null,
        public array $files = [],
    ) {}

    public function build(): self
    {
        $this->attachFiles($this->files);

        return $this->subject($this->rendered_subject)
            ->view('emails.access_link', [
                'body' => $this->body_html,
                'link_url' => $this->link_url,
                'button_label' => $this->button_label,
                'expires_on' => $this->expires_on,
                ...$this->companyLogo(),
            ]);
    }
}
