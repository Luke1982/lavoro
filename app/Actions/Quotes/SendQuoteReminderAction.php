<?php

namespace App\Actions\Quotes;

use App\Domain\Access\LinkMailer;
use App\Domain\Quotes\QuoteFiles;
use App\Domain\Quotes\QuoteMail;
use App\Domain\Signals\Quotes\QuoteReminderSent;
use App\Domain\Signals\Signals;
use App\Enums\AccessTokenPurpose;
use App\Mail\AccessLinkMail;
use App\Models\QuoteVersion;
use Illuminate\Support\Facades\DB;

/**
 * Reminds a customer who has not answered, once: the same version again, with a
 * link of its own and the same files. The link sent first keeps working, so it
 * does not matter which mail they open; whichever answer comes first is the
 * answer.
 */
class SendQuoteReminderAction
{
    public function __construct(private readonly LinkMailer $mailer) {}

    public function execute(QuoteVersion $version): void
    {
        $quote = $version->quote;
        $files = QuoteFiles::forMail($version->document());

        $this->mailer->send(
            $quote,
            AccessTokenPurpose::quote_response,
            $version->recipient,
            fn (string $url, ?string $expires_on) => new AccessLinkMail(
                QuoteMail::reminderSubject($quote),
                QuoteMail::reminderBody($quote, $version),
                $url,
                QuoteMail::BUTTON,
                $expires_on,
                $files,
            ),
            payload: ['version_id' => $version->id],
            expires_at: $quote->valid_until?->copy()->endOfDay(),
        );

        DB::transaction(function () use ($quote, $version) {
            $version->forceFill(['reminded_at' => now()])->save();
            Signals::dispatch(new QuoteReminderSent($quote, $version));
        });
    }
}
