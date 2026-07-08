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
        $event = Event::with(['serviceOrders.customer', 'customers'])->find($this->event_id);
        $standard_email = StandardEmail::with('standardAttachments')->find($this->standard_email_id);

        if (!$event || !$standard_email) {
            return;
        }

        $to = StandardEmailRenderer::defaultRecipient($event);

        if (!$to) {
            return;
        }

        $subject = StandardEmailRenderer::render($standard_email->subject, $event);
        $body = StandardEmailRenderer::render($standard_email->body, $event);

        StandardEmailSender::send($event, $standard_email, $to, $subject, $body, $this->trigger);
    }
}
