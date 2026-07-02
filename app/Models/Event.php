<?php

namespace App\Models;

use App\Enums\EventStatusses;
use App\Models\Traits\HasExecutingUsers;
use App\Models\Traits\HasOwner;
use App\Models\Traits\RemarkableTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasExecutingUsers;
    use HasFactory;
    use HasOwner;
    use RemarkableTrait;
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
        'no_service_order',
    ];

    protected $casts = [
        'start' => 'datetime',
        'end' => 'datetime',
        'is_preliminary' => 'boolean',
        'no_service_order' => 'boolean',
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

    public function customers()
    {
        return $this->morphedByMany(Customer::class, 'eventable');
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

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main', 'internal'])
            ->wherePivot('internal', false)
            ->withTimestamps();
    }
}
