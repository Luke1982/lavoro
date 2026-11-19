<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteGuard;
use App\Domain\Signals\Quotes\QuoteLineRemoved;
use App\Domain\Signals\Signals;
use App\Models\QuoteLine;

/**
 * Takes a line off a concept quote. A product takes the materials under it along,
 * through the foreign key: piping without the unit it was for is not something
 * anyone meant to offer.
 */
class RemoveQuoteLineAction
{
    public function execute(QuoteLine $line): void
    {
        QuoteGuard::editable($line->quote);

        $children = $line->children->all();
        $line->delete();

        Signals::dispatch(new QuoteLineRemoved($line->quote, $line, $children));
    }
}
