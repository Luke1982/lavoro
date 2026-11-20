<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A row in a short list someone puts in order by dragging, such as the quote
 * units or the reasons a quote was lost: a new one goes last, and the list is
 * read by position.
 *
 * @mixin Model
 */
trait KeepsPosition
{
    public static function bootKeepsPosition(): void
    {
        static::creating(function (Model $row) {
            $row->position ??= (int) static::max('position') + 1;
        });
    }

    /** @param array<int, int> $ids every row, in the order wanted */
    public static function putInOrder(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $index => $id) {
                static::whereKey($id)->update(['position' => $index + 1]);
            }
        });
    }

    /** @return Collection<int, static> */
    public static function inOrder(): Collection
    {
        return static::orderBy('position')->orderBy('id')->get();
    }
}
