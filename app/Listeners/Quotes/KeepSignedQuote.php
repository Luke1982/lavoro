<?php

namespace App\Listeners\Quotes;

use App\Domain\Signals\Quotes\QuoteAccepted;
use App\Jobs\StoreSignedQuoteJob;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * A quote the customer signed is kept as a signed PDF on the quote, and the
 * customer gets their copy by mail: a private customer is owed one, and anyone
 * else is glad of it. Only once the yes is certain, and in the background, so
 * the customer's page answers without waiting for a PDF.
 */
class KeepSignedQuote implements ShouldHandleEventsAfterCommit
{
    public function handle(QuoteAccepted $signal): void
    {
        StoreSignedQuoteJob::dispatch($signal->quote, $signal->recipient);
    }
}
