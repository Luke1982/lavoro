<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notification switched off by someone who gets it unless they say otherwise.
 * Only types that UserNotificationType calls mutable can be muted.
 */
class NotificationMute extends Model
{
    protected $fillable = [
        'user_id',
        'type',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** By the subscriptions' rule: one's own, and everybody's for whoever may set other people's bell. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return match (true) {
            $user === null => $query->whereRaw('1 = 0'),
            $user->can('manageOthers', NotificationSubscription::class) => $query,
            default => $query->where($query->qualifyColumn('user_id'), $user->id),
        };
    }
}
