<?php

namespace App\Models;

use App\Models\Traits\HasCustomFields;
use App\Models\Traits\RemarkableTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\HasOwner;
use App\Models\Traits\HasExecutingUsers;
use App\Models\Traits\HasActivities;
use Carbon\Carbon;
use App\Enums\EventStatusses;
use App\Enums\ServiceOrderTypes;

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
        'external_purchaseorder_no',
        'actual_start_time',
        'actual_end_time',
        'service_order_stage_id',
        'type',
    ];

    protected $casts = [
        'sent_to_administration' => 'boolean',
        'sent_to_customer' => 'boolean',
        'type' => ServiceOrderTypes::class,
    ];

    protected $appends = ['is_closed'];

    protected $with = ['serviceOrderStage'];

    protected static function booted(): void
    {
        static::creating(function (ServiceOrder $service_order) {
            if ($service_order->service_order_stage_id === null) {
                $first_stage = ServiceOrderStage::orderBy('order')->first();
                if ($first_stage) {
                    $service_order->service_order_stage_id = $first_stage->id;
                }
            }
        });
    }

    public function getIsClosedAttribute(): bool
    {
        return $this->serviceOrderStage?->is_closed_state === true;
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function serviceOrderStage()
    {
        return $this->belongsTo(ServiceOrderStage::class);
    }

    public function documents()
    {
        return $this->morphToMany(Document::class, 'documentable')->withTimestamps();
    }

    public function taskInstances()
    {
        return $this->hasMany(ServiceOrderTaskInstance::class);
    }

    public function advanceToPlannedStage(): void
    {
        $planned = ServiceOrderStage::where('is_planned_state', true)->first();
        if (!$planned) {
            return;
        }
        $current = $this->serviceOrderStage;
        if ($current && $current->order >= $planned->order) {
            return;
        }
        $this->service_order_stage_id = $planned->id;
        $this->save();
        $this->logActivity("Fase gewijzigd naar: {$planned->name} (door koppeling agenda)");
    }

    public function revertToPlanningCancelledStage(): void
    {
        $cancelled = ServiceOrderStage::where('is_planning_cancelled_state', true)->first();
        if (!$cancelled) {
            return;
        }
        $this->service_order_stage_id = $cancelled->id;
        $this->save();
        $this->logActivity("Fase gewijzigd naar: {$cancelled->name} (agenda item verwijderd)");
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
            'unforseen',
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
