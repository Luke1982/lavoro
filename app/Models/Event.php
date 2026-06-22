<?php

namespace App\Models;

use App\Enums\EventStatusses;
use App\Models\Traits\HasExecutingUsers;
use App\Models\Traits\HasOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasExecutingUsers;
    use HasOwner;
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
        'is_preliminary',
    ];

    protected $casts = [
        'start' => 'datetime',
        'end' => 'datetime',
        'is_preliminary' => 'boolean',
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

    public function executions(): HasMany
    {
        return $this->hasMany(EventUserExecution::class);
    }

    public function executionFor(int $user_id): EventUserExecution
    {
        return $this->executions()->firstOrCreate(
            ['user_id' => $user_id],
            ['completion_status' => 'Gepland'],
        );
    }
}
