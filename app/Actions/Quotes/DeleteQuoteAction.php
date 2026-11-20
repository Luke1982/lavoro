<?php

namespace App\Actions\Quotes;

use App\Exceptions\Refusal;
use App\Models\Quote;

/**
 * Only a concept goes. A quote a customer has seen is part of what was said to
 * them; it is declined or left to lapse, not erased.
 */
class DeleteQuoteAction
{
    public function execute(Quote $quote): void
    {
        if ($quote->sent_at !== null) {
            throw new Refusal(
                'Offerte ' . $quote->number . ' is al verstuurd. Wijs hem af in plaats van hem te verwijderen.'
            );
        }

        $quote->delete();
    }
}
