<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteGuard;
use App\Domain\Signals\Quotes\QuoteDeclined;
use App\Enums\QuoteStatuses;
use App\Exceptions\Refusal;
use App\Models\AccessToken;
use App\Models\Quote;
use App\Models\QuoteLostReason;
use Illuminate\Support\Facades\DB;

/**
 * The quote is lost: the customer says no on their page, with their name, or a
 * colleague marks it so after a phone call. The reason goes along when there is
 * one, so the lost quotes can be counted by it. Through the link only a quote
 * that is out can be declined, and the link is used up by it; any other link to
 * the quote stops working.
 */
class DeclineQuoteAction
{
    public function __construct(private readonly ChangeQuoteStatusAction $change_status) {}

    public function execute(
        Quote $quote,
        ?QuoteLostReason $reason,
        ?string $note,
        ?string $declined_by = null,
        ?AccessToken $link = null,
    ): void {
        if ($link) {
            QuoteGuard::answerable($quote);
        }

        DB::transaction(function () use ($quote, $reason, $note, $declined_by, $link) {
            $link?->useUp([
                'kind' => 'declined',
                'name' => $declined_by,
                'reason' => $reason?->name,
                'message' => $note,
            ]);

            $moved = $this->change_status->execute(
                $quote,
                QuoteStatuses::afgewezen,
                with: ['lost_reason_id' => $reason?->id, 'lost_reason_note' => $note],
                announce: fn (Quote $quote, string $previous, string $new) => new QuoteDeclined(
                    $quote,
                    $previous,
                    $new,
                    $declined_by,
                    $reason?->name,
                    $note,
                ),
            );

            if (!$moved) {
                throw new Refusal('Op offerte ' . $quote->number . ' is zojuist al gereageerd.');
            }
        });
    }
}
