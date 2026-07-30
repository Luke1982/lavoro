<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing wish to be told about one kind of fact. Carries no rights of its
 * own: whether it delivers is decided against the subscriber's permissions every
 * time the fact occurs.
 */
class NotificationSubscription extends Model
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
