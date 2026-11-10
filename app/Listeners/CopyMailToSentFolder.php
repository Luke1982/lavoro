<?php

namespace App\Listeners;

use App\Jobs\CopyToSentFolderJob;
use App\Mail\TestMail;
use App\Services\ImapSentCopier;
use Illuminate\Mail\Events\MessageSent;

/**
 * Queues a copy of every mail that went out over SMTP for the sender's Sent
 * folder: a customer's in their own mailbox, our invoices in ours. Test mails
 * are copied by TechnicalManagementController itself, which reports how it
 * went.
 */
class CopyMailToSentFolder
{
    public function handle(MessageSent $event): void
    {
        if ($event->message->getHeaders()->has(TestMail::HEADER)) {
            return;
        }

        $mailer = $event->data['mailer'] ?? null;
        $copier = ImapSentCopier::forMailer($mailer);

        if ($copier === null || !$copier->isConfigured()) {
            return;
        }

        CopyToSentFolderJob::dispatch($mailer, $event->sent->toString(), (string) $event->message->getSubject());
    }
}
