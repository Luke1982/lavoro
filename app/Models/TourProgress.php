<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How far one person got with one rondleiding: whether it was ever started (or
 * waved away), and per chapter the version they watched.
 *
 * Screen state, not a business record: it raises no signals and has no place on
 * a timeline.
 *
 * @property int $id
 * @property int $user_id
 * @property string $tour
 * @property Carbon|null $started_at
 * @property array<string, int>|null $seen
 */
class TourProgress extends Model
{
    protected $table = 'tour_progress';

    protected $fillable = [
        'user_id',
        'tour',
        'started_at',
        'seen',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'seen' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** One's own progress, and nobody else's. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $user ? $query->where($query->qualifyColumn('user_id'), $user->id) : $query->whereRaw('1 = 0');
    }

    /**
     * What the screen needs: whether to introduce the rondleiding by itself, and
     * which chapters to leave unticked.
     *
     * @return array{started: bool, seen: array<string, int>|object}
     */
    public function toScreen(): array
    {
        return [
            'started' => $this->started_at !== null,
            'seen' => (object) ($this->seen ?? []),
        ];
    }
}
