<?php

namespace App\Models;

use App\Enums\EventStatusses;
use App\Models\Traits\HasExecutingUsers;
use App\Models\Traits\HasOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasOwner;
    use HasExecutingUsers;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'event_type_id',
        'start',
        'end',
        'status',
        'location',
        'origin',
    ];

    protected $casts = [
        'start' => 'datetime',
        'end' => 'datetime',
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
