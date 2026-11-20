<?php

namespace App\Actions\Quotes;

use App\Domain\Planning\Clock;
use App\Domain\Quotes\QuoteContent;
use App\Domain\Quotes\QuoteOrigins;
use App\Domain\Quotes\QuoteSettings;
use App\Domain\Signals\Quotes\QuoteMadeFrom;
use App\Domain\Signals\Signals;
use App\Enums\QuoteStatuses;
use App\Models\Customer;
use App\Models\Quote;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A new quote, as a concept. It gets its number on saving, and is valid for a
 * month unless a date is given: Dutch law sets no term, so the quote states one.
 * It starts with the payment terms from the settings, and as for a private
 * customer when the customer has neither a KvK nor a VAT number.
 *
 * Content can come along: a template, another quote, or what a werkbon offers.
 * Then the quote starts with those texts, terms and lines, where nothing asked
 * for says otherwise, and its trail says once where they came from.
 */
class CreateQuoteAction
{
    public const DEFAULT_VALIDITY_DAYS = 30;

    /**
     * @param  array<string, mixed>  $data  the validated fields
     * @param  (Closure(Quote): array<string, mixed>)|null  $content  what it starts with, as QuoteContent holds it
     * @param  Model|null  $origin  what it was made from
     */
    public function execute(array $data, ?Closure $content = null, ?Model $origin = null): Quote
    {
        $quote_date = Carbon::parse($data['quote_date'] ?? Clock::today());
        $customer = Customer::find($data['customer_id']);

        $quote = new Quote([
            'payment_terms' => QuoteSettings::paymentTerms() ?: null,
            'is_consumer' => blank($customer?->vat_number) && blank($customer?->chamber_of_commerce_number),
            ...$data,
            'quote_date' => $quote_date,
            'valid_until' => $data['valid_until'] ?? $quote_date->copy()->addDays(self::DEFAULT_VALIDITY_DAYS),
        ]);
        $quote->status = QuoteStatuses::concept->value;

        return DB::transaction(function () use ($quote, $data, $content, $origin) {
            $body = $content ? $content($quote) : [];
            $quote->fill(Arr::except(QuoteContent::fields($body), array_keys($data)));
            $quote->origin()->associate($origin);
            $quote->save();

            QuoteContent::addLines($quote, $body);

            if ($origin !== null) {
                Signals::dispatch(new QuoteMadeFrom($quote, $origin, QuoteOrigins::label($origin)));
            }

            return $quote;
        });
    }
}
