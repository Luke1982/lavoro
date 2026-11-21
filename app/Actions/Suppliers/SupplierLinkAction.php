<?php

namespace App\Actions\Suppliers;

use App\Domain\Signals\Signals;
use App\Domain\Signals\Suppliers\SupplierLinkChanged;
use App\Domain\Signals\Suppliers\SupplierLinked;
use App\Domain\Signals\Suppliers\SupplierUnlinked;
use App\Models\Material;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

/**
 * Links a product or material to a supplier, changes that link, or takes it off.
 * The link is a row in a pivot, which tells nobody it changed, so this is the one
 * way it changes: on the screen and in a spreadsheet import alike, each saying
 * what it was before and after.
 */
class SupplierLinkAction
{
    /**
     * Links the supplier, or, when it already is, sets what $link says on the
     * link there is.
     *
     * @param  Product|Material  $item
     * @param  array{article_number?: ?string, is_preferred?: bool}  $link
     */
    public function link(Model $item, Supplier $supplier, array $link): void
    {
        $before = self::current($item, $supplier);

        $item->suppliers()->syncWithoutDetaching([$supplier->id => $link]);

        $signal = $before === null
            ? new SupplierLinked($item, $supplier, self::normalised($link))
            : self::changed($item, $supplier, $before, $link);

        if ($signal) {
            Signals::dispatch($signal);
        }
    }

    /**
     * Changes a link there is. Nothing happens to a supplier that is not linked.
     *
     * @param  Product|Material  $item
     * @param  array{article_number?: ?string, is_preferred?: bool}  $link
     */
    public function change(Model $item, Supplier $supplier, array $link): void
    {
        $before = self::current($item, $supplier);

        $item->suppliers()->updateExistingPivot($supplier->id, $link);

        if ($before !== null && $signal = self::changed($item, $supplier, $before, $link)) {
            Signals::dispatch($signal);
        }
    }

    /** @param  Product|Material  $item */
    public function unlink(Model $item, Supplier $supplier): void
    {
        $before = self::current($item, $supplier);

        $item->suppliers()->detach($supplier->id);

        if ($before !== null) {
            Signals::dispatch(new SupplierUnlinked($item, $supplier, $before));
        }
    }

    /**
     * What a save of $link did to the link, or null when it left it as it was.
     *
     * @param  array{article_number: ?string, is_preferred: bool}  $before
     * @param  array{article_number?: ?string, is_preferred?: bool}  $link
     */
    private static function changed(Model $item, Supplier $supplier, array $before, array $link): ?SupplierLinkChanged
    {
        $after = self::normalised([...$before, ...$link]);
        $fields = [];

        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $fields[$field] = [$before[$field], $value];
            }
        }

        return $fields === [] ? null : new SupplierLinkChanged($item, $supplier, $fields);
    }

    /**
     * @param  Product|Material  $item
     * @return array{article_number: ?string, is_preferred: bool}|null
     */
    private static function current(Model $item, Supplier $supplier): ?array
    {
        $pivot = $item->suppliers()->whereKey($supplier->id)->first()?->pivot;

        return $pivot === null ? null : self::normalised([
            'article_number' => $pivot->article_number,
            'is_preferred' => $pivot->is_preferred,
        ]);
    }

    /**
     * @param  array<string, mixed>  $link
     * @return array{article_number: ?string, is_preferred: bool}
     */
    private static function normalised(array $link): array
    {
        return [
            'article_number' => filled($link['article_number'] ?? null) ? (string) $link['article_number'] : null,
            'is_preferred' => (bool) ($link['is_preferred'] ?? false),
        ];
    }
}
