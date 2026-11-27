<?php

namespace App\Domain\Quotes;

use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Support\Arr;

/**
 * What a quote offers, apart from to whom: the texts, the terms and the lines,
 * as plain data. A template keeps it, a copy takes it from another quote, and a
 * werkbon or a storing gives the lines to start from. Prices are as they were;
 * the quote it goes onto is a new offer and can be changed before it goes.
 */
final class QuoteContent
{
    /** What carries over from a quote; the dates, the customer and the answers belong to the old one. */
    public const QUOTE_FIELDS = [
        'intro_text', 'closing_text', 'default_vat_percent', 'layout',
        'pricing_method', 'start_period', 'duration', 'payment_terms', 'payment_schedule',
    ];

    /** The kind first: whether a description is HTML depends on it. */
    private const LINE_FIELDS = [
        'kind', 'item_type', 'item_id', 'description', 'details', 'location_note', 'quantity', 'unit', 'unit_price',
        'cost_price', 'vat_percent', 'discount_percent', 'is_optional', 'is_chosen', 'is_provisional', 'choice_group',
    ];

    /** @return array{quote: array<string, mixed>, lines: array<int, array<string, mixed>>} */
    public static function of(Quote $quote): array
    {
        $quote->loadMissing('lines');
        $children = $quote->lines->whereNotNull('parent_id')->groupBy('parent_id');

        return [
            'quote' => $quote->only(self::QUOTE_FIELDS),
            'lines' => $quote->lines->whereNull('parent_id')->map(fn (QuoteLine $line) => [
                ...$line->only(self::LINE_FIELDS),
                'children' => $children->get($line->id, collect())
                    ->map(fn (QuoteLine $child) => $child->only(self::LINE_FIELDS))->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * The texts and terms a new quote starts with, over the defaults. Whether
     * prices include VAT is left to the new quote's own customer.
     *
     * @param  array{quote?: array<string, mixed>}  $content
     * @return array<string, mixed>
     */
    public static function fields(array $content): array
    {
        $fields = Arr::only($content['quote'] ?? [], self::QUOTE_FIELDS);

        if (isset($fields['layout'])) {
            $fields['layout'] = Arr::except($fields['layout'], QuoteLayout::PER_CUSTOMER_KIND);
        }

        return $fields;
    }

    /**
     * Puts the lines onto a quote just made, after the ones it has. Without a
     * signal per line: the quote's trail says once where they all came from.
     *
     * @param  array{lines?: array<int, array<string, mixed>>}  $content
     */
    public static function addLines(Quote $quote, array $content): void
    {
        foreach ($content['lines'] ?? [] as $line) {
            $parent = $quote->addLine(Arr::only($line, self::LINE_FIELDS));

            foreach ($line['children'] ?? [] as $child) {
                $parent->addChild(Arr::only($child, self::LINE_FIELDS));
            }
        }

        $quote->unsetRelation('lines');
    }
}
