<?php

namespace App\Listeners\Quotes;

use App\Domain\Signals\Quotes\QuoteStatusChanged;
use App\Enums\QuoteStatuses;

/**
 * A quote taken back to concept is going to change, so the link the customer
 * holds stops working: they could otherwise accept a version that is no longer
 * the one offered. The next send gives them a new link to what is.
 *
 * In the transaction of the status change, so a status that does not move
 * leaves the link alone.
 */
class WithdrawQuoteLinksWhenReopened
{
    public function handle(QuoteStatusChanged $signal): void
    {
        if (QuoteStatuses::from($signal->new_status) === QuoteStatuses::concept) {
            $signal->quote->withdrawLinks();
        }
    }
}
