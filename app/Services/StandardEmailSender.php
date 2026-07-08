<?php

namespace App\Services;

use App\Mail\StandardEmailMail;
use App\Models\Event;
use App\Models\StandardEmail;
use Illuminate\Support\Facades\Mail;

class StandardEmailSender
{
    /**
     * Send an already-rendered standard e-mail for an event and log the send
     * as an activity on that event.
     */
    public static function send(
        Event $event,
        StandardEmail $standard_email,
        string $to,
        string $subject,
        string $body,
        ?string $trigger = null
    ): void {
        Mail::to($to)->send(new StandardEmailMail($subject, $body, $standard_email->standardAttachments));

        $event->logActivity(
            "Standaard e-mail '" . $standard_email->name . "' verzonden aan " . $to,
            metadata: [
                'standard_email_id' => $standard_email->id,
                'trigger' => $trigger,
                'to' => $to,
                'subject' => $subject,
            ],
        );
    }
}
