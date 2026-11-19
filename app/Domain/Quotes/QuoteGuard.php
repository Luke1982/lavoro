<?php

namespace App\Domain\Quotes;

use App\Exceptions\Refusal;
use App\Models\Quote;

/**
 * Says no, in words the person can act on, when a quote may not be changed.
 */
final class QuoteGuard
{
    public static function editable(Quote $quote): void
    {
        if (!$quote->statusEnum()->allowsEditing()) {
            throw new Refusal(
                'Offerte ' . $quote->number . ' is ' . mb_strtolower($quote->status)
                . '. Zet hem eerst terug naar concept om hem aan te passen.'
            );
        }
    }
}
