<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteGuard;
use App\Domain\Signals\Quotes\QuoteChangesRequested;
use App\Enums\QuoteStatuses;
use App\Models\AccessToken;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;

/**
 * The customer asks for something to be different, through the link they were
 * sent. That is their answer, so the link is used up; the quote opens for the
 * change and goes to them again with a new one.
 */
class RequestQuoteChangesAction
{
    public function __construct(private readonly ChangeQuoteStatusAction $change_status) {}

    public function execute(Quote $quote, string $name, string $message, AccessToken $link): void
    {
        QuoteGuard::answerable($quote);

        DB::transaction(function () use ($quote, $name, $message, $link) {
            $link->useUp(['kind' => 'changes', 'name' => $name, 'message' => $message]);

            $this->change_status->execute(
                $quote,
                QuoteStatuses::wijziging_gevraagd,
                announce: fn (Quote $quote, string $previous, string $new) => new QuoteChangesRequested(
                    $quote,
                    $previous,
                    $new,
                    $name,
                    $message,
                ),
            );
        });
    }
}
