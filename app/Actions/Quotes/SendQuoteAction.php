<?php

namespace App\Actions\Quotes;

use App\Domain\Access\LinkMailer;
use App\Domain\Planning\Clock;
use App\Domain\Quotes\QuoteGuard;
use App\Domain\Signals\Quotes\QuoteSentToCustomer;
use App\Domain\Signals\Signals;
use App\Enums\AccessTokenPurpose;
use App\Enums\QuoteStatuses;
use App\Exceptions\Refusal;
use App\Mail\AccessLinkMail;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;

/**
 * Sends a quote to the customer, with a link to look at it and answer: accept
 * it, or ask for a change. Sending again after a change is the same thing; once
 * the new mail is out, the link sent before stops working, so there is only
 * ever one way to answer, and it shows what is offered now.
 *
 * Every send is a new version: the quote frozen as the customer gets it, which
 * the link then shows. The link lasts as long as the quote is valid. The mail
 * goes before anything is recorded: if it does not go out, the quote is as it
 * was.
 */
class SendQuoteAction
{
    private const BUTTON = 'Offerte bekijken';

    public function __construct(
        private readonly LinkMailer $mailer,
        private readonly ChangeQuoteStatusAction $change_status,
    ) {}

    public function execute(Quote $quote, string $to, string $subject, string $body): void
    {
        if ($quote->statusEnum() === QuoteStatuses::geaccepteerd) {
            throw new Refusal('Offerte ' . $quote->number . ' is al geaccepteerd.');
        }

        QuoteGuard::offersSomething($quote);

        if ($quote->valid_until !== null && $quote->valid_until->toDateString() < Clock::today()) {
            throw new Refusal(
                'Offerte ' . $quote->number . ' was geldig tot ' . $quote->valid_until->format('d-m-Y')
                . '. Kies eerst een nieuwe datum.'
            );
        }

        $issued = $this->mailer->send(
            $quote,
            AccessTokenPurpose::quote_response,
            $to,
            fn (string $url, ?string $expires_on) => new AccessLinkMail(
                $subject,
                $body,
                $url,
                self::BUTTON,
                $expires_on,
            ),
            expires_at: $quote->valid_until?->copy()->endOfDay(),
        );

        DB::transaction(function () use ($quote, $to, $issued) {
            $quote->withdrawLinks(except: $issued->token);
            $version = $quote->recordVersion($to, $issued->token);

            $announce = fn (Quote $quote, string $previous, string $new) => new QuoteSentToCustomer(
                $quote,
                $previous,
                $new,
                $to,
                $issued->token->expires_at,
                $version->number,
            );

            if (!$this->change_status->execute($quote, QuoteStatuses::verzonden, announce: $announce)) {
                Signals::dispatch($announce($quote, $quote->status, $quote->status));
            }
        });
    }
}
