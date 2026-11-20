<?php

namespace App\Domain\Quotes;

use App\Exceptions\Refusal;
use App\Models\Quote;

/**
 * Says no, in words the person can act on, when a quote may not have done to
 * it what was asked.
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

    /** Sending or accepting a quote without a single amount on it, options included: there is nothing to say yes to. */
    public static function offersSomething(Quote $quote): void
    {
        if ($quote->totals()->isEmpty()) {
            throw new Refusal('Offerte ' . $quote->number . ' heeft nog geen regels met een bedrag.');
        }
    }

    /** A customer answers through the link only while the quote is out with them. */
    public static function answerable(Quote $quote): void
    {
        if (!$quote->statusEnum()->awaitsAnswer()) {
            throw new Refusal('Op deze offerte kan niet meer worden gereageerd. Neem gerust contact met ons op.');
        }
    }
}
