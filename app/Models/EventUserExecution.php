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
        'signature_base64',
    ];

    protected $casts = [
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
