<?php

namespace App\Actions\Quotes;

use App\Domain\Signals\Quotes\QuoteViewed;
use App\Domain\Signals\Signals;
use App\Models\Quote;
use App\Models\QuoteVersion;
use Illuminate\Support\Facades\DB;

/**
 * The customer opened the link to a send. The first time is news: whoever made
 * the quote hears it, and the version keeps when. One statement that only
 * succeeds while nobody looked yet, so two tabs opening at once tell it once.
 */
class RecordQuoteViewAction
{
    public function execute(Quote $quote, QuoteVersion $version): void
    {
        DB::transaction(function () use ($quote, $version) {
            $first = QuoteVersion::whereKey($version->getKey())->whereNull('viewed_at')->update(['viewed_at' => now()]);

            if ($first === 1) {
                Signals::dispatch(new QuoteViewed($quote, $version->refresh()));
            }
        });
    }
}
