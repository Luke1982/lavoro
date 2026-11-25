<?php

namespace App\Models\Traits;

use App\Models\Share;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A record that can be shared from where it lives to somewhere else. Its shares
 * go when it goes: the link has no foreign key to do that for it.
 *
 * @mixin Model
 */
trait Shareable
{
    public static function bootShareable(): void
    {
        static::deleted(fn (self $model) => $model->shares()->delete());
    }

    public function shares(): MorphMany
    {
        return $this->morphMany(Share::class, 'shareable');
    }

    /** Whether it is shared from this record onwards: a project photo onto the project's werkbonnen. */
    public function isSharedFrom(?Model $source): bool
    {
        return Share::canShareFrom($source) && $this->shares()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->exists();
    }
}
