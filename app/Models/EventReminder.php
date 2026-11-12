<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * That someone was reminded of an appointment starting at a given moment. Kept
 * apart from the notification itself, which the reader may delete or a later
 * notification may supersede; this stays so the moment is never announced twice.
 * Once the moment has passed it is pruned: a start in the past is never
 * announced anyway.
 */
class EventReminder extends Model
{
    protected $fillable = [
        'event_id',
        'user_id',
        'starts_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
        ];
    }
}
