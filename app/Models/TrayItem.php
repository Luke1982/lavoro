<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One thing a person has set aside on their bakje.
 *
 * @property int $id
 * @property int $user_id
 * @property string $trayable_type
 * @property int $trayable_id
 * @property bool $pinned
 * @property int $position
 */
class TrayItem extends Model
{
    protected $fillable = [
        'user_id',
        'trayable_type',
        'trayable_id',
        'pinned',
        'position',
    ];

    protected $casts = [
        'pinned' => 'boolean',
        'position' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** One's own bakje, and nobody else's. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $user ? $query->where($query->qualifyColumn('user_id'), $user->id) : $query->whereRaw('1 = 0');
    }

    public function trayable(): MorphTo
    {
        return $this->morphTo();
    }
}
