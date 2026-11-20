<?php

namespace App\Actions\Quotes;

use App\Domain\Signals\Quotes\QuoteVersionStarted;
use App\Domain\Signals\Signals;
use App\Enums\QuoteStatuses;
use App\Exceptions\Refusal;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;

/**
 * Whoever makes the quote says the next change is a new version, not a
 * correction of the one the customer has: after the customer asked for
 * something else, or when the offer itself changes. Sending the same version
 * again, after fixing a typo, keeps its number.
 *
 * Only a version that went out can be followed by a new one; until then, the
 * version is simply still being written. The links to the old version stop
 * working, and a quote that was out goes back to concept, so it can be changed.
 */
class StartNewQuoteVersionAction
{
    public function __construct(private readonly ChangeQuoteStatusAction $change_status) {}

    public function execute(Quote $quote): Quote
    {
        if ($quote->statusEnum() === QuoteStatuses::geaccepteerd) {
            throw new Refusal('Offerte ' . $quote->number . ' is geaccepteerd. Zet hem eerst terug naar concept.');
        }

        if ($quote->latestSendOfVersion() === null) {
            throw new Refusal(
                'Versie ' . $quote->version . ' van offerte ' . $quote->number . ' is nog niet verstuurd. '
                . 'Pas hem gewoon aan; hij krijgt pas een nieuw nummer als je dat na het versturen kiest.'
            );
        }

        return DB::transaction(function () use ($quote) {
            if (!$quote->statusEnum()->allowsEditing()) {
                $this->change_status->execute($quote, QuoteStatuses::concept, 'voor een nieuwe versie');
            }

            $quote->withdrawLinks();

            $previous = $quote->version;
            $quote->forceFill(['version' => $previous + 1])->save();

            Signals::dispatch(new QuoteVersionStarted($quote, $previous));

            return $quote;
        });
    }
}
