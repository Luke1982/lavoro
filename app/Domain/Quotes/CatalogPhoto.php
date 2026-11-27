<?php

namespace App\Domain\Quotes;

use App\Models\Image;
use App\Models\Material;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * The photo a product or material is offered with: a product's main photo, a
 * material's main photo or else its first. A quote line and a search result
 * show the same one.
 */
final class CatalogPhoto
{
    /** What to load on a product or a material before asking for its photo. */
    public const RELATIONS = [
        Product::class => ['mainImage'],
        Material::class => ['images'],
    ];

    /** @return array{id: int, path: string}|null */
    public static function of(?Model $item): ?array
    {
        $image = match (true) {
            $item instanceof Product => $item->mainImage->first(),
            $item instanceof Material => $item->images->sortByDesc('pivot.main')->first(),
            default => null,
        };

        return $image instanceof Image ? $image->only(['id', 'path']) : null;
    }
}
