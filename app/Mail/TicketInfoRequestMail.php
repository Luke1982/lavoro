<?php

namespace App\Mail;

/** The request for more information on a storing, with the button to the upload page. */
class TicketInfoRequestMail extends AccessLinkMail
{
    public function __construct(
        string $rendered_subject,
        string $body_html,
        string $upload_url,
        ?string $expires_on = null,
    ) {
        parent::__construct($rendered_subject, $body_html, $upload_url, 'Informatie aanleveren', $expires_on);
    }
}
