<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A link that keeps its place in the list it belongs to: the checks inside one
 * checklist, the checks or checklists on one task. The owner is the morph the
 * link hangs off, named by the model's OWNER constant.
 *
 * @mixin Model
 */
trait OrderedWithinOwner
{
    /** The place after the last link of this owner. */
    public static function nextSortOrder(string $owner_type, int $owner_id): int
    {
        return (int) static::query()
            ->where(static::OWNER . '_type', $owner_type)
            ->where(static::OWNER . '_id', $owner_id)
            ->max('sort_order') + 1;
    }

    /**
     * Whether these are existing links of one and the same owner, the only links
     * that can share an order.
     *
     * @param  array<int, int>  $ids
     */
    public static function shareOneOwner(array $ids): bool
    {
        $links = static::query()->findMany($ids);

        return $links->count() === count($ids)
            && $links->map(fn (Model $link) => $link->{static::OWNER . '_type'} . ':' . $link->{static::OWNER . '_id'})
                ->unique()->count() === 1;
    }

    /**
     * Puts the given links in this order.
     *
     * @param  array<int, int>  $ids
     */
    public static function putInOrder(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $position => $id) {
                static::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
    }
}
