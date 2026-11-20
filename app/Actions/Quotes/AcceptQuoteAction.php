<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteAlternatives;
use App\Domain\Quotes\QuoteForCustomer;
use App\Domain\Quotes\QuoteGuard;
use App\Domain\Signals\Quotes\QuoteAccepted;
use App\Enums\QuoteAcceptedVia;
use App\Enums\QuoteStatuses;
use App\Exceptions\Refusal;
use App\Models\AccessToken;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteVersion;
use Illuminate\Support\Facades\DB;

/**
 * The customer says yes, with their name and signature: through the link they
 * were sent, or on a screen in the app. Through the link only a sent quote can
 * be accepted, and the link is used up by it; in the app the customer signs in
 * front of someone, and a concept will do.
 *
 * The choices: who may choose them chooses which optional lines are taken and
 * which of each set of alternatives. On the link that is the customer, when the
 * quote lets them; otherwise what the quote already counts is taken.
 *
 * Along with the yes go the general terms the version was offered under, which
 * the request made sure were accepted, and for a private customer whether the
 * work may start within the 14 days to change their mind. What was signed is
 * kept as it was: the send, with the choices made.
 *
 * A yes is the one answer: any other link to the quote stops working, and a yes
 * that loses a race with another answer is refused, taking its choices back.
 */
class AcceptQuoteAction
{
    public function __construct(private readonly ChangeQuoteStatusAction $change_status) {}

    /** @param array<int, int|string> $chosen_ids the optional lines and alternatives taken */
    public function execute(
        Quote $quote,
        string $name,
        string $signature,
        array $chosen_ids,
        ?AccessToken $link = null,
        bool $starts_within_cooling_off = false,
    ): void {
        $via = $link ? QuoteAcceptedVia::link : QuoteAcceptedVia::app;

        if ($link) {
            QuoteGuard::answerable($quote);
        }

        QuoteGuard::offersSomething($quote);

        if ($link && !$quote->customer_picks_options) {
            $chosen_ids = $quote->lines()->where('is_chosen', true)->pluck('id')->all();
        }

        $early = $quote->is_consumer ? $starts_within_cooling_off : null;

        DB::transaction(function () use ($quote, $name, $signature, $chosen_ids, $link, $via, $early) {
            $link?->useUp(['kind' => 'accepted', 'name' => $name]);
            $version = $this->versionAccepted($quote, $link);

            $chosen = $this->choose($quote, array_map('intval', $chosen_ids));

            $moved = $this->change_status->execute(
                $quote,
                QuoteStatuses::geaccepteerd,
                with: [
                    'accepted_name' => $name,
                    'accepted_signature' => $signature,
                    'accepted_via' => $via->value,
                    'accepted_version_id' => $version->id,
                    'accepted_terms_name' => $version->document()['terms']['name'] ?? null,
                    'starts_within_cooling_off' => $early,
                ],
                announce: fn (Quote $quote, string $previous, string $new) => new QuoteAccepted(
                    $quote,
                    $previous,
                    $new,
                    $name,
                    $via,
                    $chosen,
                    $version->number,
                    $link?->recipient,
                ),
            );

            if (!$moved) {
                throw new Refusal('Op offerte ' . $quote->number . ' is zojuist al gereageerd.');
            }

            $quote->forceFill(['accepted_document' => QuoteForCustomer::accepted($version, $quote)])->save();
            $quote->withdrawLinks();
        });
    }

    /**
     * The version said yes to: the one the link showed; in the app, the one last
     * sent when the quote has not changed since (a sent quote cannot change),
     * and otherwise the quote as it stands, recorded now.
     */
    private function versionAccepted(Quote $quote, ?AccessToken $link): QuoteVersion
    {
        return ($link ? QuoteVersion::shownBy($link) : $quote->sendOnOffer()) ?? $quote->recordVersion();
    }

    /**
     * Marks every option as taken or not, and of each set of alternatives the
     * one picked; a set nobody picked from keeps the one that counted.
     *
     * @param  array<int, int>  $chosen_ids
     * @return array<int, string> what was taken, as the trail names it
     */
    private function choose(Quote $quote, array $chosen_ids): array
    {
        $choosable = $quote->lines()->whereNull('parent_id')
            ->where(fn ($query) => $query->where('is_optional', true)->orWhereNotNull('choice_group'))
            ->get();

        $choosable->where('is_optional', true)
            ->each(fn (QuoteLine $option) => $option->update([
                'is_chosen' => in_array($option->id, $chosen_ids, true),
            ]));

        $choosable->whereNotNull('choice_group')
            ->filter(fn (QuoteLine $alternative) => in_array($alternative->id, $chosen_ids, true))
            ->each(fn (QuoteLine $alternative) => QuoteAlternatives::choose($alternative));

        $quote->unsetRelation('lines');

        return $quote->lines()->whereNull('parent_id')->where('is_chosen', true)
            ->where(fn ($query) => $query->where('is_optional', true)->orWhereNotNull('choice_group'))
            ->pluck('description')->all();
    }
}
