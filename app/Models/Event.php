<?php

namespace App\Models;

use App\Enums\EventStatusses;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\HasOwner;
use App\Models\Traits\HasExecutingUsers;

class Event extends Model
{
    use HasOwner;
    use HasExecutingUsers;

    protected $fillable = [
        'name',
        'description',
        'event_type_id',
        'start',
        'end',
        'status',
        'location',
    ];

    public static function statusses()
    {
        return EventStatusses::comboBoxArray();
    }

    public function eventType()
    {
        return $this->belongsTo(EventType::class);
    }

    public function serviceOrders()
    {
        return $this->morphedByMany(ServiceOrder::class, 'eventable');
    }
}
