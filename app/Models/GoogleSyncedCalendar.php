<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleSyncedCalendar extends Model
{
    protected $fillable = [
        'google_calendar_integration_id',
        'owner_user_id',
        'google_calendar_id',
        'summary',
        'sync_token',
        'watch_channel_id',
        'watch_channel_token',
        'watch_resource_id',
        'watch_expires_at',
        'last_full_sync_at',
    ];

    protected $casts = [
        'watch_expires_at' => 'datetime',
        'last_full_sync_at' => 'datetime',
    ];

    public function integration()
    {
        return $this->belongsTo(GoogleCalendarIntegration::class, 'google_calendar_integration_id');
    }

    public function ownerUser()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function syncedEvents()
    {
        return $this->hasMany(GoogleSyncedEvent::class);
    }

    public function isOwnersOwnCalendar(): bool
    {
        return $this->owner_user_id === $this->integration?->user_id;
    }
}
