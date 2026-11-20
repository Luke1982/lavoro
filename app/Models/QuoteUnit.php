<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A unit a quote line can be offered in, such as "uur" or "m²". A line keeps
 * the unit as text, so renaming or removing one here leaves the quotes made
 * with it as they are.
 */
class QuoteUnit extends Model
{
    protected $fillable = ['name', 'position'];

    protected static function booted(): void
    {
        static::creating(function (QuoteUnit $unit) {
            $unit->position ??= (int) static::max('position') + 1;
        });
    }

    /** @param array<int, int> $ids every unit, in the order wanted */
    public static function putInOrder(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $index => $id) {
                static::whereKey($id)->update(['position' => $index + 1]);
            }
        });
    }
}
