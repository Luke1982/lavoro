<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\PaymentSchedule;
use App\Domain\Quotes\QuoteGuard;
use App\Domain\Quotes\QuoteLayout;
use App\Models\Quote;
use Illuminate\Support\Arr;

/**
 * Changes the heading of a concept quote: title, dates, address, contact,
 * texts, and the terms it is offered under. The model trail records each field
 * before and after. Columns sent along are stored as a whole choice, the rest
 * as the customer sees them now, so the trail reads what changed.
 */
class UpdateQuoteAction
{
    /** @param array<string, mixed> $data the validated fields */
    public function execute(Quote $quote, array $data): Quote
    {
        QuoteGuard::editable($quote);

        $quote->update([...$data, ...Arr::only(self::normalized($quote, $data), array_keys($data))]);

        return $quote;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{payment_schedule: ?array, layout: ?array} the terms as stored, and every column chosen
     */
    private static function normalized(Quote $quote, array $data): array
    {
        return [
            'payment_schedule' => PaymentSchedule::normalize($data['payment_schedule'] ?? null),
            'layout' => isset($data['layout'])
                ? QuoteLayout::complete($data['layout'], $quote->layoutForCustomer())
                : null,
        ];
    }
}
