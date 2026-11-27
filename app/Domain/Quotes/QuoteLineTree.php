<?php

namespace App\Domain\Quotes;

use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The lines of a quote as a screen draws them: each line at the top, with the
 * materials that came along with it underneath, and each line's total. The
 * staff page and the customer's page build it the same way; the customer's
 * leaves out what is internal, and names the photo by id only: the PDF draws
 * it, the files themselves are only served to someone logged in.
 */
final class QuoteLineTree
{
    /** What a customer may see of a line: no cost price, no catalogue link. */
    private const FOR_CUSTOMER = [
        'id', 'parent_id', 'kind', 'description', 'details', 'location_note', 'quantity', 'unit', 'unit_price',
        'vat_percent', 'discount_percent', 'is_optional', 'is_chosen', 'is_provisional', 'choice_group',
    ];

    /** @return array<int, array<string, mixed>> */
    public static function of(Quote $quote, bool $for_customer = false): array
    {
        $quote->lines->load(['item' => fn (MorphTo $item) => $item->morphWith(CatalogPhoto::RELATIONS)]);

        $row = fn (QuoteLine $line) => $for_customer
            ? [
                ...$line->only(self::FOR_CUSTOMER),
                'net' => $line->netCents() / 100,
                'image_id' => CatalogPhoto::of($line->item)['id'] ?? null,
            ]
            : [
                ...$line->withoutRelations()->toArray(),
                'net' => $line->netCents() / 100,
                'image' => CatalogPhoto::of($line->item),
            ];
        $children = $quote->lines->whereNotNull('parent_id')->groupBy('parent_id');

        return $quote->lines
            ->whereNull('parent_id')
            ->map(fn (QuoteLine $line) => [
                ...$row($line),
                'children' => $children->get($line->id, collect())->map($row)->values()->all(),
            ])
            ->values()
            ->all();
    }
}
