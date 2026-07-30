<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser that may be interrupted, and the keys to do it with.
 *
 * Rows are disposable by design: a push service that answers "gone" means the
 * browser threw the subscription away, and the row goes with it rather than being
 * retried for ever.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'user_agent',
    ];

    protected $hidden = [
        'public_key',
        'auth_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
