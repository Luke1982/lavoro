<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\StandardEmail;
use App\Services\StandardEmailRenderer;
use App\Services\StandardEmailSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendStandardEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $event_id,
        public int $standard_email_id,
        public ?string $trigger = null,
    ) {}

    public function handle(): void
    {
        $event = Event::withTrashed()->find($this->event_id);
        $standard_email = StandardEmail::with('standardAttachments')->find($this->standard_email_id);

        if (!$event || !$standard_email) {
            return;
        }

        $mail = StandardEmailRenderer::compose($standard_email, $event);

        if (!$mail['to']) {
            return;
        }

        StandardEmailSender::send(
            $event,
            $standard_email,
            $mail['to'],
            $mail['subject'],
            $mail['body'],
            $this->trigger,
        );
    }
}
