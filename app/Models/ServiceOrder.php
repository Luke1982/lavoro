<?php

namespace App\Models;

use App\Enums\EventStatusses;
use App\Enums\ServiceOrderTypes;
use App\Models\Traits\HasActivities;
use App\Models\Traits\HasCustomFields;
use App\Models\Traits\HasExecutingUsers;
use App\Models\Traits\HasOwner;
use App\Models\Traits\RemarkableTrait;
use Carbon\Carbon;
use Database\Factories\ServiceOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ServiceOrder extends Model
{
    use HasActivities;
    use HasCustomFields;
    use HasExecutingUsers;

    /** @use HasFactory<ServiceOrderFactory> */
    use HasFactory;

    use HasOwner;
    use RemarkableTrait;

    protected $fillable = [
        'description',
        'customer_id',
        'location_id',
        'project_id',
        'maintenance_contract_id',
        'closed_on',
        'signed_by',
        'signature_base64',
        'sent_to_administration',
        'sent_to_customer',
        'external_purchaseorder_no',
        'external_invoice_no',
        'financial_comments',
        'execution_location',
        'actual_start_time',
        'actual_end_time',
        'service_order_stage_id',
        'work_completed',
        'type',
    ];

    protected $casts = [
        'sent_to_administration' => 'boolean',
        'sent_to_customer' => 'boolean',
        'work_completed' => 'boolean',
        'type' => ServiceOrderTypes::class,
    ];

    /**
     * Work being finished is the normal outcome, so the switch starts on and is turned
     * off for the exception. Repeating the column default here keeps a fresh instance in
     * step with it, so the switch never sees the null it renders as a third state.
     */
    protected $attributes = [
        'work_completed' => true,
    ];

    protected $appends = ['is_closed', 'is_incomplete', 'is_invoiced'];

    protected $with = ['serviceOrderStage', 'linkedLocation'];

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

        static::deleting(function (ServiceOrder $service_order) {
            $id = $service_order->id;
            $morph_class = ServiceOrder::class;
            $pivot_tables = [
                'eventables' => 'eventable',
                'remarkables' => 'remarkable',
                'imageables' => 'imageable',
                'documentables' => 'documentable',
                'materiables' => 'materiable',
                'activityables' => 'activityable',
                'customfieldables' => 'customfieldable',
                'userables' => 'userable',
            ];

            foreach ($pivot_tables as $table => $morph) {
                DB::table($table)
                    ->where("{$morph}_type", $morph_class)
                    ->where("{$morph}_id", $id)
                    ->delete();
            }
        });
    }

    public function getIsClosedAttribute(): bool
    {
        return $this->serviceOrderStage?->is_closed_state === true;
    }

    public function getIsIncompleteAttribute(): bool
    {
        return $this->serviceOrderStage?->is_incomplete_state === true;
    }

    public function getIsInvoicedAttribute(): bool
    {
        return $this->serviceOrderStage?->is_invoiced_state === true;
    }

    /**
     * The order's execution address plus which field it came from, so a caller
     * that displays it can say what it is showing. Stops before the customer:
     * that fallback belongs to whoever is displaying, not to this order.
     */
    public function locationWithSource(): array
    {
        if ($this->linkedLocation) {
            return ['address' => $this->linkedLocation->addressLine(), 'source' => 'location'];
        }
        if (!empty($this->execution_location)) {
            return ['address' => $this->execution_location, 'source' => 'execution_location'];
        }

        $project_location = $this->relationLoaded('project') ? $this->project?->location : null;

        return !empty($project_location)
            ? ['address' => $project_location, 'source' => 'project']
            : ['address' => null, 'source' => null];
    }

    public function getResolvedLocationAttribute(): ?string
    {
        return $this->locationWithSource()['address'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function linkedLocation()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function maintenanceContract()
    {
        return $this->belongsTo(MaintenanceContract::class);
    }

    public function serviceOrderStage()
    {
        return $this->belongsTo(ServiceOrderStage::class);
    }

    public function documents()
    {
        return $this->morphToMany(Document::class, 'documentable')
            ->withPivot('internal')
            ->wherePivot('internal', false)
            ->withTimestamps();
    }

    public function internalDocuments()
    {
        return $this->morphToMany(Document::class, 'documentable')
            ->withPivot('internal')
            ->wherePivot('internal', true)
            ->withTimestamps();
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main', 'internal'])
            ->wherePivot('internal', false)
            ->withTimestamps();
    }

    public function internalImages()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main', 'internal'])
            ->wherePivot('internal', true)
            ->withTimestamps();
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

    /**
     * Repoints the order at another customer. The linked location is dropped
     * along the way: locations belong to a customer, so the old one is by
     * definition unreachable from the new one. The project link is deliberately
     * kept — unlinking it is a bigger decision than this move gets to make.
     */
    public function moveToCustomer(int $customer_id): void
    {
        $previous = $this->customer?->name;

        $this->update([
            'customer_id' => $customer_id,
            'location_id' => null,
        ]);

        $new = $this->refresh()->customer?->name;
        $this->logActivity('Klant gewijzigd van ' . $previous . ' naar ' . $new . ' (via agenda)');
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

    public function freeformMaterials()
    {
        return $this->hasMany(FreeformMaterial::class);
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
