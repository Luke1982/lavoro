<?php

namespace App\Jobs;

use App\Services\ImapSentCopier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Puts a sent mail back in the sender's Sent folder, away from the request
 * that sent it: an IMAP server that is slow or unreachable would otherwise
 * hold up every mail for its timeouts.
 *
 * Queued before commit, because the mail has left whatever the transaction
 * around it does. Encrypted, because the payload is the whole mail,
 * attachments and all. One try: an APPEND whose answer got lost may well
 * have landed, and a second one would file the mail twice.
 */
class CopyToSentFolderJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function __construct(
        public string $mailer,
        public string $raw_message,
        public string $subject,
    ) {
        $this->beforeCommit();
    }

    public function handle(): void
    {
        $copier = ImapSentCopier::forMailer($this->mailer);

        if ($copier === null || !$copier->isConfigured()) {
            return;
        }

        try {
            $copier->copy($this->raw_message);
        } catch (Throwable $e) {
            logger()->error('Failed to copy sent mail to IMAP sent folder.', [
                'mailer' => $this->mailer,
                'error' => $e->getMessage(),
                'subject' => $this->subject,
            ]);
        }
    }
}
