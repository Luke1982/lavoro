<?php

namespace App\Models;

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
}
