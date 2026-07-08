<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class StandardEmailMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $rendered_subject,
        public string $rendered_body,
        public Collection $standard_attachments,
    ) {}

    public function build(): self
    {
        $mail = $this->subject($this->rendered_subject)
            ->view('emails.standard_email', ['body' => $this->rendered_body]);

        foreach ($this->standard_attachments as $attachment) {
            $mail->attach(Storage::disk('public')->path($attachment->path), [
                'as' => $attachment->original_filename,
                'mime' => $attachment->mime_type,
            ]);
        }

        return $mail;
    }
}
