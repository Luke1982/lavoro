<?php

namespace App\Domain\Quotes;

use App\Models\Image;
use App\Models\Material;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The lines of a quote as a screen draws them: each line at the top, with the
 * materials that came along with it underneath, and each line's total. The
 * staff page and the customer's page build it the same way; the customer's
 * leaves out what is internal, and the photos, which are only served to
 * someone logged in.
 */
final class QuoteLineTree
{
    /** What a customer may see of a line: no cost price, no catalogue link. */
    private const FOR_CUSTOMER = [
        'id', 'parent_id', 'kind', 'description', 'details', 'quantity', 'unit', 'unit_price',
        'vat_percent', 'discount_percent', 'is_optional', 'is_chosen',
    ];

    /** @return array<int, array<string, mixed>> */
    public static function of(Quote $quote, bool $for_customer = false): array
    {
        if (!$for_customer) {
            $quote->lines->load(['item' => fn (MorphTo $item) => $item->morphWith([
                Product::class => ['mainImage'],
                Material::class => ['images'],
            ])]);
        }

        $row = fn (QuoteLine $line) => $for_customer
            ? [...$line->only(self::FOR_CUSTOMER), 'net' => $line->netCents() / 100]
            : [
                ...$line->withoutRelations()->toArray(),
                'net' => $line->netCents() / 100,
                'image' => self::image($line),
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

    /** @return array{id: int, path: ?string}|null the catalogue photo of what the line offers */
    private static function image(QuoteLine $line): ?array
    {
        $image = match (true) {
            $line->item instanceof Product => $line->item->mainImage->first(),
            $line->item instanceof Material => $line->item->images->sortByDesc('pivot.main')->first(),
            default => null,
        };

        return $image instanceof Image ? $image->only(['id', 'path']) : null;
    }
}
