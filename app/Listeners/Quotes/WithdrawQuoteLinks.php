<?php

namespace App\Listeners\Quotes;

use App\Domain\Signals\Quotes\QuoteSignal;
use App\Domain\Signals\Quotes\QuoteStatusChanged;
use App\Domain\Signals\Quotes\QuoteVersionStarted;
use App\Enums\QuoteStatuses;

/**
 * The links the customer holds stop working once what they show is no longer
 * on offer: the quote was answered (accepted, declined, a change asked for),
 * taken back to concept to be changed, or followed by a new version. They could
 * otherwise accept a version that is no longer the one offered, or answer twice.
 * The next send gives them a new link to what is.
 *
 * A quote that lapses keeps its links: a lapsed quote cannot be answered, and
 * one sent again by hand is answerable on the link the customer already has.
 *
 * Typed on the interface, because Laravel hands an event only to the listeners
 * of its own class and its interfaces: a listener on QuoteStatusChanged would
 * never hear QuoteAccepted or QuoteDeclined. In the transaction of the change,
 * so a status that does not move leaves the links alone.
 */
class WithdrawQuoteLinks
{
    private const ENDING_THE_OFFER = [
        QuoteStatuses::concept,
        QuoteStatuses::wijziging_gevraagd,
        QuoteStatuses::geaccepteerd,
        QuoteStatuses::afgewezen,
    ];

    public function handle(QuoteSignal $signal): void
    {
        if ($signal instanceof QuoteVersionStarted || $this->endsTheOffer($signal)) {
            $signal->quote()->withdrawLinks();
        }
    }

    private function endsTheOffer(QuoteSignal $signal): bool
    {
        return $signal instanceof QuoteStatusChanged
            && in_array(QuoteStatuses::tryFrom($signal->new_status), self::ENDING_THE_OFFER, true);
    }
}
