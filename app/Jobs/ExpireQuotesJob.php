<?php

namespace App\Jobs;

use App\Actions\Quotes\ChangeQuoteStatusAction;
use App\Domain\Planning\Clock;
use App\Enums\QuoteStatuses;
use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Every morning: a sent quote whose term ran out yesterday or earlier without an
 * answer lapses. The timeline says the app did it, and why.
 */
class ExpireQuotesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ChangeQuoteStatusAction $change): void
    {
        Quote::query()
            ->where('status', QuoteStatuses::verzonden->value)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', Clock::today())
            ->with(['customer', 'project', 'lines'])
            ->lazyById()
            ->each(fn (Quote $quote) => DB::transaction(fn () => $change->execute(
                $quote,
                QuoteStatuses::vervallen,
                'geldig tot ' . $quote->valid_until->format('d-m-Y') . ' zonder antwoord',
            )));
    }
}
