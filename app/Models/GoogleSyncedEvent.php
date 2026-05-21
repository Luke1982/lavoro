<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleSyncedEvent extends Model
{
    protected $fillable = [
        'google_synced_calendar_id',
        'event_id',
        'google_event_id',
        'etag',
        'last_pushed_at',
    ];

    protected $casts = [
        'last_pushed_at' => 'datetime',
    ];

    public function syncedCalendar()
    {
        return $this->belongsTo(GoogleSyncedCalendar::class, 'google_synced_calendar_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
