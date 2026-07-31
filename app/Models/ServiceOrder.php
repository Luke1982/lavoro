<?php

namespace App\Models;

use App\Domain\Signals\ServiceOrders\ServiceOrderCustomerChanged;
use App\Domain\Signals\ServiceOrders\ServiceOrderStageChanged;
use App\Domain\Signals\Signals;
use App\Enums\EventStatusses;
use App\Enums\ServiceOrderTypes;
use App\Models\Traits\HasActivities;
use App\Models\Traits\HasCustomFields;
use App\Models\Traits\HasExecutingUsers;
use App\Models\Traits\HasMaterials;
use App\Models\Traits\HasOwner;
use App\Models\Traits\RecordsHistory;
use App\Models\Traits\RemarkableTrait;
use App\Services\MateriableService;
use Carbon\Carbon;
use Database\Factories\ServiceOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServiceOrder extends Model
{
    use HasActivities;
    use HasCustomFields;
    use HasExecutingUsers;

    /** @use HasFactory<ServiceOrderFactory> */
    use HasFactory;

    use HasMaterials;
    use HasOwner;
    use RecordsHistory;
    use RemarkableTrait;

    protected array $activity_labels = [
        'signed_by' => 'Ondertekend door',
        'execution_location' => 'Uitvoeringslocatie',
        'actual_start_time' => 'Werkelijke starttijd',
        'actual_end_time' => 'Werkelijke eindtijd',
        'service_order_stage_id' => 'Fase',
        'customer_id' => 'Klant',
        'location_id' => 'Locatie',
        'project_id' => 'Project',
        'maintenance_contract_id' => 'Contract',
        'description' => 'Omschrijving',
        'order_date' => 'Datum opdracht',
        'closed_on' => 'Afgesloten op',
        'external_invoice_no' => 'Extern factuurnummer',
        'financial_comments' => 'Financiële opmerkingen',
        'external_purchaseorder_no' => 'Extern inkoopordernummer',
        'work_completed' => 'Werk afgerond',
        'sent_to_customer' => 'Verzonden naar klant',
        'sent_to_administration' => 'Verzonden naar administratie',
        'type' => 'Type',
    ];

    protected array $activity_relations = [
        'service_order_stage_id' => ['serviceOrderStage', 'name'],
        'customer_id' => ['customer', 'name'],
        'project_id' => ['project', 'title'],
    ];

    /**
     * Fields whose changes are announced by a named event of their own. Leaving
     * them here too would log the same fact twice.
     */
    protected array $activity_ignore = [
        'signature_base64',
        'customer_id',
        'service_order_stage_id',
        'closed_on',
        'sent_to_administration',
    ];

    /** Fields whose entries are gated behind a permission. */
    protected array $activity_permissions = [
        'financial_comments' => 'serviceorder.see_financials',
    ];

    protected $fillable = [
        'description',
        'order_date',
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
        'order_date' => 'date:Y-m-d',
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

            if ($service_order->order_date === null) {
                $service_order->order_date = now(config('app.display_timezone'))->toDateString();
            }
        });

        /**
         * Task instances are cascaded away by the database, so no model event ever fires
         * for them: their materials are released here, while the order still knows which
         * instances it has. Materials are absent from the pivot list below because they
         * move stock and go through MateriableService instead.
         */
        static::deleting(function (ServiceOrder $service_order) {
            $id = $service_order->id;
            $morph_class = ServiceOrder::class;
            $materiables = app(MateriableService::class);
            $pivot_tables = [
                'eventables' => 'eventable',
                'remarkables' => 'remarkable',
                'imageables' => 'imageable',
                'documentables' => 'documentable',
                'activityables' => 'activityable',
                'customfieldables' => 'customfieldable',
                'userables' => 'userable',
            ];

            foreach ($service_order->taskInstances as $task_instance) {
                $materiables->release(
                    $task_instance,
                    'verwijdering werkbon #' . $id
                );
            }

            $materiables->release($service_order, 'verwijdering werkbon #' . $id);

            foreach ($pivot_tables as $table => $morph) {
                DB::table($table)
                    ->where("{$morph}_type", $morph_class)
                    ->where("{$morph}_id", $id)
                    ->delete();
            }
        });
    }

    /**
     * Limits a query to the orders this person is allowed to see: everything if
     * they may read orders at large, otherwise only the ones they are executing.
     *
     * Anything that shows orders to a user goes through here. The rule used to be
     * written out at each call site, which is how a new reader of the data ends up
     * seeing more than the old one.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user && ($user->isAdmin() || $user->hasPermission('serviceorder.read'))) {
            return $query;
        }

        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('executingUsers', fn ($q) => $q->where('users.id', $user->id));
    }

    /**
     * Het nummer zoals iedereen het kent: WB gevolgd door vier cijfers. De vorm
     * stond op drie plaatsen los uitgeschreven en hoort bij de werkbon zelf, niet
     * bij wie hem toevallig afdrukt.
     */
    public function getNumberAttribute(): string
    {
        return 'WB-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function getIsClosedAttribute(): bool
    {
        return $this->serviceOrderStage?->closesOrder() === true;
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

    /**
     * The only supported way to change an order's stage. Everything that follows
     * from a stage move — the closing timestamp, the log line, whatever a
     * subscriber decides later — hangs off the signal this emits, so no caller
     * has to remember any of it.
     */
    public function moveToStage(?ServiceOrderStage $stage, ?string $reason = null): bool
    {
        if ($this->service_order_stage_id === $stage?->id) {
            return false;
        }

        $previous_stage = $this->serviceOrderStage;
        $was_closed = $this->is_closed;

        $this->service_order_stage_id = $stage?->id;
        $this->setRelation('serviceOrderStage', $stage);

        $is_closed = $this->is_closed;

        if ($is_closed && !$was_closed) {
            $this->closed_on = now();
        } elseif (!$is_closed && $was_closed) {
            $this->closed_on = null;
        }

        $this->save();

        Signals::dispatch(new ServiceOrderStageChanged($this, $previous_stage, $stage, $reason));

        return true;
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

        $this->moveToStage($planned, 'door koppeling agenda');
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

        $previous_customer_id = $this->getOriginal('customer_id');
        $new = $this->refresh()->customer?->name;

        Signals::dispatch(new ServiceOrderCustomerChanged(
            $this,
            $previous_customer_id,
            $previous,
            $new,
            reason: 'via agenda',
        ));
    }

    public function revertToPlanningCancelledStage(): void
    {
        $cancelled = ServiceOrderStage::where('is_planning_cancelled_state', true)->first();
        if (!$cancelled) {
            return;
        }

        $this->moveToStage($cancelled, 'agenda item verwijderd');
    }

    public function serviceJobs()
    {
        return $this->hasMany(ServiceJob::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * The order's own materials plus everything booked against its task instances, which is
     * what the customer is billed for. Task-sourced lines carry the task on the pivot so the
     * widget and the PDF can say what they were used for.
     *
     * Not appended: it reaches through every task instance, so only the pages and exports
     * that need the full picture ask for it, and they eager load taskInstances.materials.
     *
     * @return Collection<int, Material>
     */
    public function allMaterials(): Collection
    {
        return $this->withTaskLines(
            $this->materials,
            fn (ServiceOrderTaskInstance $task_instance) => $task_instance->materials
        );
    }

    /**
     * @return Collection<int, FreeformMaterial>
     */
    public function allFreeformMaterials(): Collection
    {
        return $this->withTaskLines(
            $this->freeformMaterials,
            fn (ServiceOrderTaskInstance $task_instance) => $task_instance->freeformMaterials
        );
    }

    private function withTaskLines(Collection $own_lines, callable $task_lines): Collection
    {
        $lines = $own_lines->each(fn (Model $line) => $line->setAttribute('task_instance', null));

        foreach ($this->taskInstances as $task_instance) {
            $lines = $lines->concat($task_lines($task_instance)->each(
                fn (Model $line) => $line->setAttribute('task_instance', $task_instance)
            ));
        }

        return $lines;
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
