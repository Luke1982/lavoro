<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventUserExecution extends Model
{
    protected $fillable = [
        'event_id',
        'user_id',
        'completion_status',
        'actual_start',
        'actual_end',
        'travel_time_minutes',
        'signature_base64',
    ];

    protected $casts = [
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'travel_time_minutes' => 'integer',
    ];

    /**
     * The part of this execution that the activity trail reports on, frozen as
     * plain values so a later save cannot change what was recorded as "before".
     *
     * @return array<string, string|int|null>
     */
    public function snapshot(): array
    {
        return [
            'completion_status' => $this->completion_status,
            'actual_start' => $this->actual_start?->toDateTimeString(),
            'actual_end' => $this->actual_end?->toDateTimeString(),
            'travel_time_minutes' => (int) $this->travel_time_minutes,
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
