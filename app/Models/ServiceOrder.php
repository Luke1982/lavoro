<?php

namespace App\Models;

use App\Models\Traits\HasCustomFields;
use App\Models\Traits\RemarkableTrait;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\HasOwner;
use App\Models\Traits\HasExecutingUsers;
use App\Models\Traits\HasActivities;
use Carbon\Carbon;
use App\Enums\EventStatusses;

class ServiceOrder extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceOrderFactory> */
    use HasFactory;
    use RemarkableTrait;
    use HasOwner;
    use HasExecutingUsers;
    use HasActivities;
    use HasCustomFields;

    protected $fillable = [
        'description',
        'customer_id',
        'project_id',
        'closed_on',
        'signed_by',
        'signature_base64',
        'sent_to_administration',
        'sent_to_customer',
        'status',
        'external_purchaseorder_no',
        'actual_start_time',
        'actual_end_time',
    ];

    protected $casts = [
        'sent_to_administration' => 'boolean',
        'sent_to_customer' => 'boolean',
        'status' => 'string',
    ];

    public function getIsClosedAttribute(): bool
    {
        $status = is_string($this->status) ? strtolower($this->status) : null;
        return $status === 'closed';
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function serviceJobs()
    {
        return $this->hasMany(ServiceJob::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function materials()
    {
        return $this->morphToMany(
            Material::class,
            'materiable',
        )->withPivot(
            'quantity',
            'material_role_id',
            'id'
        )->withTimestamps();
    }

    public function events()
    {
        return $this->morphToMany(Event::class, 'eventable');
    }

    public function pastOpenEvents()
    {
        return $this->morphToMany(Event::class, 'eventable')
            ->where('start', '<', Carbon::now())
            ->where('status', '!=', EventStatusses::completed->value)
            ->orderBy('start', 'desc');
    }

    public function comingEvents()
    {
        return $this->morphToMany(Event::class, 'eventable')
            ->where('start', '>=', Carbon::now())
            ->orderBy('start', 'asc');
    }
}
