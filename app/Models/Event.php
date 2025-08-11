<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = [
        'name',
        'description',
        'event_type_id',
        'start_time',
        'end_time',
        'location',
    ];

    public function type()
    {
        return $this->belongsTo(EventType::class);
    }
}
