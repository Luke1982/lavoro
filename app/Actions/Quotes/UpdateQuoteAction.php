<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteGuard;
use App\Models\Quote;

/**
 * Changes the heading of a concept quote: title, dates, address, contact and
 * texts. The model trail records each field before and after.
 */
class UpdateQuoteAction
{
    /** @param array<string, mixed> $data the validated fields */
    public function execute(Quote $quote, array $data): Quote
    {
        QuoteGuard::editable($quote);

        $quote->update($data);

        return $quote;
    }
}
