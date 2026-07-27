<?php

namespace App\Services;

use App\Domain\Signals\Appointments\StandardEmailSent;
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

        event(new StandardEmailSent($event, $standard_email, $to, $subject, $trigger));
    }
}
