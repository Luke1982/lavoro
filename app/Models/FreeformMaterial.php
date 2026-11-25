<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property float $quantity
 * @property string $description
 * @property bool $unforseen
 */
class FreeformMaterial extends Model
{
    protected $fillable = [
        'quantity',
        'description',
        'unforseen',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unforseen' => 'boolean',
    ];

    public function freeformmateriable()
    {
        return $this->morphTo();
    }

    /**
     * With the right to read vrij materiaal, the lines on a werkbon or a task this
     * person may see.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (!$user?->can('viewAny', self::class)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHasMorph(
            'freeformmateriable',
            [ServiceOrder::class, ServiceOrderTaskInstance::class],
            fn (Builder $owner) => $owner->visibleTo($user)
        );
    }
}
