<?php

namespace App\Jobs;

use App\Actions\Quotes\SendQuoteReminderAction;
use App\Domain\Access\LinkNotSent;
use App\Domain\Planning\Clock;
use App\Domain\Quotes\QuoteSettings;
use App\Domain\Signals\Quotes\QuoteExpiresSoon;
use App\Domain\Signals\Signals;
use App\Enums\QuoteStatuses;
use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Every morning, for the quotes that are out and not answered: the customer is
 * reminded once, the set number of days after the last send of the version, as
 * long as that send's link is still open, and whoever made the quote hears when
 * it runs out within the set number of days. Either is off when its setting is
 * blank. A quote put back on "verzonden" by hand, after it was changed, has no
 * open send, so nothing is revived that no longer holds.
 *
 * A reminder that cannot be mailed is tried again the next morning; the others
 * go on.
 */
class FollowUpQuotesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SendQuoteReminderAction $remind): void
    {
        $reminder_days = QuoteSettings::reminderDays();
        $notice_days = QuoteSettings::expiryNoticeDays();

        Quote::query()
            ->where('status', QuoteStatuses::verzonden->value)
            ->with(['customer', 'contact'])
            ->lazyById()
            ->each(function (Quote $quote) use ($remind, $reminder_days, $notice_days) {
                if ($reminder_days !== null) {
                    $this->remind($quote, $reminder_days, $remind);
                }

                if ($notice_days !== null) {
                    $this->noticeExpiry($quote, $notice_days);
                }
            });
    }

    private function remind(Quote $quote, int $days, SendQuoteReminderAction $remind): void
    {
        $send = $quote->latestSendOfVersion();

        if ($send === null || $send->reminded_at !== null || !$send->isOpen()
            || $send->created_at->gt(now()->subDays($days))) {
            return;
        }

        try {
            $remind->execute($send);
        } catch (LinkNotSent) {
        }
    }

    private function noticeExpiry(Quote $quote, int $days): void
    {
        $until = $quote->valid_until?->toDateString();

        if ($until === null || $until < Clock::today() || $until > Clock::todayAsDate()->addDays($days)->toDateString()
            || $quote->expiry_noticed_for?->toDateString() === $until) {
            return;
        }

        DB::transaction(function () use ($quote) {
            $quote->forceFill(['expiry_noticed_for' => $quote->valid_until])->save();
            Signals::dispatch(new QuoteExpiresSoon($quote));
        });
    }
}
