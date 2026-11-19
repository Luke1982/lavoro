<?php

namespace App\Actions\Quotes;

use App\Domain\Planning\Clock;
use App\Enums\QuoteStatuses;
use App\Models\Quote;
use Illuminate\Support\Carbon;

/**
 * A new quote, as a concept. It gets its number on saving, and is valid for a
 * month unless a date is given: Dutch law sets no term, so the quote states one.
 */
class CreateQuoteAction
{
    public const DEFAULT_VALIDITY_DAYS = 30;

    /** @param array<string, mixed> $data the validated fields */
    public function execute(array $data): Quote
    {
        $quote_date = Carbon::parse($data['quote_date'] ?? Clock::today());

        $quote = new Quote([
            ...$data,
            'quote_date' => $quote_date,
            'valid_until' => $data['valid_until'] ?? $quote_date->copy()->addDays(self::DEFAULT_VALIDITY_DAYS),
        ]);
        $quote->status = QuoteStatuses::concept->value;
        $quote->save();

        return $quote;
    }
}
