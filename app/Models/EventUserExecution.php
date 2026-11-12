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
        'actual_breaktime_minutes',
        'signature_base64',
    ];

    protected $casts = [
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'travel_time_minutes' => 'integer',
        'actual_breaktime_minutes' => 'integer',
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
            'actual_breaktime_minutes' => $this->actual_breaktime_minutes,
        ];
    }

    /**
     * The break that counts: the one the technician registered, or the one planned
     * for them when they registered none.
     */
    public function breakMinutes(int $planned_break_minutes): int
    {
        return $this->actual_breaktime_minutes ?? $planned_break_minutes;
    }

    public function hoursWorked(int $planned_break_minutes): ?float
    {
        if (!$this->actual_start || !$this->actual_end) {
            return null;
        }

        $break_hours = $this->breakMinutes($planned_break_minutes) / 60;

        return round($this->actual_start->floatDiffInHours($this->actual_end) - $break_hours, 2);
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
