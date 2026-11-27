<?php

namespace App\Domain\Quotes;

use App\Enums\QuoteTermsKinds;
use App\Models\Company;
use App\Models\Quote;
use App\Models\QuoteVersion;
use Illuminate\Support\Arr;

/**
 * A quote as the customer receives it: the heading, who makes it, the texts,
 * the lines and the totals, the terms it is offered under, the files shared
 * with the customer, and nothing internal. The customer's page, the PDF and
 * the preview a colleague opens all show exactly this, and a version freezes
 * it.
 *
 * The general terms are frozen with what the version needs to hand them out
 * again later: the file they are stored in, the text, or the page; the page
 * only ever gets their kind, name and address. What of()
 * gives is complete; complete() fills in what a version frozen before the quote
 * said all this lacks.
 */
final class QuoteForCustomer
{
    /** What a stelpost is, wherever the customer reads a quote that has one: the page and the PDF. */
    public const PROVISIONAL_EXPLANATION = 'Een stelpost is een bedrag dat nog niet vaststaat. Wij verrekenen het achteraf op basis van de werkelijke kosten.';

    /** @return array<string, mixed> */
    public static function of(Quote $quote): array
    {
        $quote->loadMissing(['customer', 'contact', 'location', 'lines']);
        $totals = $quote->totals();
        $seller = Company::main()?->details() ?? [];

        return [
            'quote' => [
                ...$quote->only([
                    'number', 'version', 'title', 'intro_text', 'closing_text',
                    'is_consumer', 'start_period', 'duration', 'payment_terms',
                ]),
                'quote_date' => $quote->quote_date?->toDateString(),
                'valid_until' => $quote->valid_until?->toDateString(),
                'customer' => $quote->customer?->name,
                'contact' => $quote->contact?->full_name,
                'address' => self::address($quote),
                'location' => self::location($quote),
                'pricing_method' => $quote->pricing_method->value,
                'pricing_explanation' => $quote->pricing_method->explanation(),
                'payment_schedule' => PaymentSchedule::withAmounts($quote->payment_schedule, $totals->grossCents()),
            ],
            'layout' => $quote->layoutForCustomer(),
            'seller' => $seller,
            'terms' => QuoteSettings::terms(),
            'withdrawal' => $quote->is_consumer ? WithdrawalRight::for($seller) : null,
            'lines' => QuoteLineTree::of($quote, for_customer: true),
            'totals' => $totals->toArray(with_margin: false),
            'attachments' => QuoteFiles::shared($quote),
        ];
    }

    /**
     * A snapshot with everything a page or a PDF reads, also when it was frozen
     * before the quote said all this: an older version reads as it was shown.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function complete(array $snapshot, ?int $version = null): array
    {
        return [
            'seller' => Company::main()?->details() ?? [],
            'terms' => null,
            'withdrawal' => null,
            'attachments' => [],
            ...$snapshot,
            'terms' => isset($snapshot['terms'])
                ? ['kind' => QuoteTermsKinds::pdf->value, ...$snapshot['terms']]
                : null,
            'layout' => QuoteLayout::complete($snapshot['layout'] ?? null),
            'quote' => [
                'is_consumer' => false,
                'payment_schedule' => [],
                'pricing_method' => null,
                'pricing_explanation' => null,
                ...$snapshot['quote'],
                'version' => $version ?? $snapshot['quote']['version'] ?? 1,
            ],
        ];
    }

    /**
     * The send as it was accepted: everything it went out with, the terms and
     * the company among them, and the choices made with the yes. A sent quote
     * cannot change, so its lines are the ones sent, with what was chosen.
     *
     * @return array<string, mixed>
     */
    public static function accepted(QuoteVersion $send, Quote $quote): array
    {
        $sent = $send->document();
        $now = self::of($quote);

        return [
            ...$sent,
            'quote' => [...$sent['quote'], 'payment_schedule' => $now['quote']['payment_schedule']],
            'lines' => $now['lines'],
            'totals' => $now['totals'],
        ];
    }

    /**
     * What the customer's page gets: the terms by kind, name and the page they
     * are kept on, never where a file is stored or the text itself, which the
     * link hands over as a file; and what a stelpost is, as the PDF says it.
     *
     * @param  array<string, mixed>  $document  a completed snapshot
     * @return array<string, mixed>
     */
    public static function forPage(array $document): array
    {
        return [
            ...$document,
            'terms' => $document['terms'] ? Arr::only($document['terms'], ['kind', 'name', 'url']) : null,
            'attachments' => array_column($document['attachments'] ?? [], 'name'),
            'provisional_explanation' => self::PROVISIONAL_EXPLANATION,
        ];
    }

    /**
     * The quote as it stands, as the customer's page would show it if it went
     * out now.
     *
     * @return array<string, mixed>
     */
    public static function page(Quote $quote): array
    {
        return self::forPage(self::of($quote));
    }

    /** Where the work is, when it is not the customer's own address. */
    private static function location(Quote $quote): ?string
    {
        return $quote->location
            ? trim($quote->location->title . ', ' . $quote->location->addressLine(), ', ')
            : null;
    }

    /** @return array<int, string> the customer's address, as a letter is addressed */
    private static function address(Quote $quote): array
    {
        $customer = $quote->customer;

        return array_values(array_filter([
            $customer?->address,
            trim(($customer?->postal_code ?? '') . ' ' . ($customer?->city ?? '')) ?: null,
        ]));
    }
}
