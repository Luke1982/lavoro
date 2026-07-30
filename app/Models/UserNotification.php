<?php

namespace App\Models;

use App\Enums\UserNotificationPriority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One thing one person has been told.
 *
 * The title and the body are written once and never regenerated, so this model
 * needs nothing loaded to be shown. The record it points at is there for the
 * link, not for the sentence, which is why a notification about a deleted storing
 * still reads correctly.
 *
 * type stays a plain string rather than an enum cast: the sentences are already
 * frozen, so nothing here needs the enum, and a type that is retired one day
 * should not make its old notifications unreadable.
 */
class UserNotification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'priority',
        'notificationable_type',
        'notificationable_id',
        'title',
        'body',
        'read_at',
    ];

    /**
     * The priority is cast where the type is not: it is a closed set of three
     * levels, so having the enum in hand is worth more than tolerating a value
     * from outside it.
     */
    protected function casts(): array
    {
        return [
            'priority' => UserNotificationPriority::class,
            'read_at' => 'datetime',
        ];
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notificationable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Where clicking this should land. Resolved here rather than in the front end
     * because the service worker needs it too: a notification acted on while the
     * app is closed has no Vue around to work it out.
     */
    public function linkPath(): ?string
    {
        return match ($this->notificationable_type) {
            Ticket::class => '/tickets/' . $this->notificationable_id,
            default => null,
        };
    }

    /**
     * Acknowledging twice keeps the first moment, so a second click does not
     * quietly restate when somebody read it.
     */
    public function acknowledge(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }

    public function unacknowledge(): void
    {
        $this->update(['read_at' => null]);
    }
}
