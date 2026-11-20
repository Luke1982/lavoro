<?php

namespace App\Domain\Quotes;

use App\Models\Quote;

/**
 * A quote as the customer receives it: the heading, the texts, the lines and
 * the totals, and nothing internal. The customer's page and the preview a
 * colleague opens show exactly this.
 */
final class QuoteForCustomer
{
    /** @return array{quote: array<string, mixed>, lines: array<int, array<string, mixed>>, totals: array<string, mixed>} */
    public static function of(Quote $quote): array
    {
        $quote->loadMissing(['customer', 'contact', 'lines']);

        return [
            'quote' => [
                ...$quote->only(['number', 'title', 'intro_text', 'closing_text', 'customer_picks_options']),
                'quote_date' => $quote->quote_date?->toDateString(),
                'valid_until' => $quote->valid_until?->toDateString(),
                'customer' => $quote->customer?->name,
                'contact' => $quote->contact?->full_name,
            ],
            'lines' => QuoteLineTree::of($quote, for_customer: true),
            'totals' => $quote->totals()->toArray(with_margin: false),
        ];
    }
}
