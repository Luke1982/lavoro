<?php

namespace App\Listeners;

use App\Services\ImapSentCopier;
use Illuminate\Mail\Events\MessageSent;
use Throwable;

class CopyMailToSentFolder
{
    public function handle(MessageSent $event): void
    {
        if (config('mail.default') !== 'smtp') {
            return;
        }

        // Test mails are handled explicitly by TechnicalManagementController
        if ($event->message->getHeaders()->has('X-Test-Mail')) {
            return;
        }

        $copier = app(ImapSentCopier::class);

        if (!$copier->isConfigured()) {
            return;
        }

        try {
            $copier->copy($event->sent->toString());
        } catch (Throwable $e) {
            logger()->error('Failed to copy sent mail to IMAP sent folder.', [
                'error'   => $e->getMessage(),
                'subject' => $event->message->getSubject(),
            ]);
        }
    }
}
