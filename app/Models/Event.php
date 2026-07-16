<?php

namespace App\Models;

use App\Enums\EventStatusses;
use App\Models\Traits\HasActivities;
use App\Models\Traits\HasExecutingUsers;
use App\Models\Traits\HasOwner;
use App\Models\Traits\RemarkableTrait;
use App\Services\EventLocationResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasActivities;
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
        'location_id',
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

    protected $appends = ['resolved_location'];

    /** Kept eager so the appended resolved_location can never trigger an N+1. */
    protected $with = ['linkedLocation'];

    public static function statusses()
    {
        return EventStatusses::comboBoxArray();
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (!$user || $user->hasPermission('event.see_all')) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->whereHas('executingUsers', fn ($sq) => $sq->where('users.id', $user->id))
                ->orWhereHas('owners', fn ($sq) => $sq->where('users.id', $user->id)->where('userables.type', 'owner'));
        });
    }

    public function eventType()
    {
        return $this->belongsTo(EventType::class);
    }

    /**
     * The linked customer location. Deliberately not named `location()`: that
     * name is already taken by the free-text `location` column, which would
     * shadow the relation on attribute access.
     */
    public function linkedLocation()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * This appointment's own location: a linked location's address wins over the
     * free-text location, which is only a snapshot.
     */
    public function getResolvedLocationAttribute(): ?string
    {
        if ($this->linkedLocation) {
            return $this->linkedLocation->addressLine();
        }

        return $this->location ?: null;
    }

    /**
     * The full escalation (see EventLocationResolver) — the address actually
     * shown for this appointment. Append it explicitly on queries that eager-load
     * serviceOrders; it is not appended globally to avoid an N+1.
     */
    public function getDisplayLocationAttribute(): ?string
    {
        return app(EventLocationResolver::class)->resolve($this);
    }

    public function serviceOrders()
    {
        return $this->morphedByMany(ServiceOrder::class, 'eventable');
    }

    public function customers()
    {
        return $this->morphedByMany(Customer::class, 'eventable');
    }

    public function primaryCustomer(): ?Customer
    {
        return $this->serviceOrders->first()?->customer ?? $this->customers->first();
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
