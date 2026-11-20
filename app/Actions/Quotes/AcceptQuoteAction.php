<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteGuard;
use App\Domain\Signals\Quotes\QuoteAccepted;
use App\Enums\QuoteAcceptedVia;
use App\Enums\QuoteStatuses;
use App\Models\AccessToken;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;

/**
 * The customer says yes, with their name and signature: through the link they
 * were sent, or on a screen in the app. Through the link only a sent quote can
 * be accepted, and the link is used up by it; in the app the customer signs in
 * front of someone, and a concept will do.
 *
 * The options: who may choose them chooses which optional lines are taken. On
 * the link that is the customer, when the quote lets them; otherwise none are.
 */
class AcceptQuoteAction
{
    public function __construct(private readonly ChangeQuoteStatusAction $change_status) {}

    /** @param array<int, int|string> $chosen_ids the optional lines taken */
    public function execute(
        Quote $quote,
        string $name,
        string $signature,
        array $chosen_ids,
        ?AccessToken $link = null,
    ): void {
        $via = $link ? QuoteAcceptedVia::link : QuoteAcceptedVia::app;

        if ($link) {
            QuoteGuard::answerable($quote);
        }

        QuoteGuard::offersSomething($quote);

        if ($link && !$quote->customer_picks_options) {
            $chosen_ids = [];
        }

        DB::transaction(function () use ($quote, $name, $signature, $chosen_ids, $link, $via) {
            $link?->useUp(['kind' => 'accepted', 'name' => $name]);

            $chosen = $this->choose($quote, array_map('intval', $chosen_ids));

            $this->change_status->execute(
                $quote,
                QuoteStatuses::geaccepteerd,
                with: ['accepted_name' => $name, 'accepted_signature' => $signature, 'accepted_via' => $via->value],
                announce: fn (Quote $quote, string $previous, string $new) => new QuoteAccepted(
                    $quote,
                    $previous,
                    $new,
                    $name,
                    $via,
                    $chosen,
                ),
            );
        });
    }

    /**
     * Marks every optional line as taken or not.
     *
     * @param  array<int, int>  $chosen_ids
     * @return array<int, string> what was taken, as the trail names it
     */
    private function choose(Quote $quote, array $chosen_ids): array
    {
        $optional = fn () => $quote->lines()->where('is_optional', true);

        $optional()->whereIn('id', $chosen_ids)->update(['is_chosen' => true]);
        $optional()->whereNotIn('id', $chosen_ids)->update(['is_chosen' => false]);
        $quote->unsetRelation('lines');

        return $optional()->where('is_chosen', true)->pluck('description')->all();
    }
}
