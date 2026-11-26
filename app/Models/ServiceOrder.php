<?php

namespace App\Models;

use App\Domain\Search\SearchTerm;
use App\Domain\Signals\ServiceOrders\ServiceOrderCustomerChanged;
use App\Domain\Signals\ServiceOrders\ServiceOrderStageChanged;
use App\Domain\Signals\ServiceOrders\TicketDetachedFromOrder;
use App\Domain\Signals\Signals;
use App\Enums\EventStatusses;
use App\Enums\ServiceOrderTypes;
use App\Models\Traits\HasActivities;
use App\Models\Traits\HasCustomFields;
use App\Models\Traits\HasExecutingUsers;
use App\Models\Traits\HasIntegrations;
use App\Models\Traits\HasMaterials;
use App\Models\Traits\HasOwner;
use App\Models\Traits\RecordsHistory;
use App\Models\Traits\RemarkableTrait;
use App\Services\MateriableService;
use Carbon\Carbon;
use Database\Factories\ServiceOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServiceOrder extends Model
{
    use HasActivities;
    use HasCustomFields;
    use HasExecutingUsers;

    /** @use HasFactory<ServiceOrderFactory> */
    use HasFactory;

    use HasIntegrations;
    use HasMaterials;
    use HasOwner;
    use RecordsHistory;
    use RemarkableTrait;

    public const FINANCIAL_ATTRIBUTES = [
        'financial_comments',
    ];

    protected array $activity_labels = [
        'signed_by' => 'Ondertekend door',
        'execution_location' => 'Uitvoeringslocatie',
        'actual_start_time' => 'Werkelijke starttijd',
        'actual_end_time' => 'Werkelijke eindtijd',
        'service_order_stage_id' => 'Fase',
        'customer_id' => 'Klant',
        'location_id' => 'Locatie',
        'contact_id' => 'Contactpersoon',
        'project_id' => 'Project',
        'project_milestone_id' => 'Mijlpaal',
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
        'location_id' => ['linkedLocation', 'title'],
        'contact_id' => ['contact', 'full_name'],
        'project_id' => ['project', 'title'],
    ];

    /**
     * Fields whose changes are announced by a named event of their own. Leaving
     * them here too would log the same fact twice. A contract link is only ever
     * set when the werkbon is generated and only ever cleared by
     * ServiceOrderUnlinkedFromContract.
     */
    protected array $activity_ignore = [
        'signed_by',
        'signature_base64',
        'customer_id',
        'service_order_stage_id',
        'closed_on',
        'sent_to_administration',
        'project_milestone_id',
        'sent_to_customer',
        'maintenance_contract_id',
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
        'contact_id',
        'project_id',
        'project_milestone_id',
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

    /**
     * Hidden by default because a werkbon travels along on the planner, the
     * customer, projects and the event API. The werkbon page makes them visible
     * for whoever may see them; their changes still reach the trail, gated by
     * $activity_permissions.
     */
    protected $hidden = self::FINANCIAL_ATTRIBUTES;

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
         * for them: their materials and their chosen bundle-aantallen are released here,
         * while the order still knows which instances it has. Materials are absent from the
         * pivot list below because they move stock and go through MateriableService instead.
         *
         * Images are absent from it too: DeleteOrphanedImagesJob finds the photos of a deleted
         * order through those links, so they have to outlive it.
         *
         * Its storingen are let go by the database as well, so each one is told here, while
         * the order still knows its appointments: a storing that was in behandeling because
         * of this order goes back to open, and the storing's own timeline says why.
         */
        static::deleting(function (ServiceOrder $service_order) {
            $id = $service_order->id;
            $morph_class = ServiceOrder::class;
            $materiables = app(MateriableService::class);
            $pivot_tables = [
                'eventables' => 'eventable',
                'remarkables' => 'remarkable',
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

                $task_instance->productables()->delete();
            }

            $materiables->release($service_order, 'verwijdering werkbon #' . $id);

            $tickets = $service_order->tickets()
                ->with(['asset.product.brand', 'asset.product.productType'])
                ->get();

            foreach ($tickets as $ticket) {
                Signals::dispatch(new TicketDetachedFromOrder(
                    $service_order,
                    $ticket,
                    $ticket->asset,
                    order_deleted: true,
                ));
            }

            foreach ($pivot_tables as $table => $morph) {
                DB::table($table)
                    ->where("{$morph}_type", $morph_class)
                    ->where("{$morph}_id", $id)
                    ->delete();
            }
        });
    }

    /**
     * Whether a keuring for this machine belongs on this werkbon: the machine is
     * the werkbon customer's, a part by the machine it hangs under.
     */
    public function isForTheCustomerOf(Asset $machine): bool
    {
        return $this->customer_id !== null && $machine->resolvedCustomerId() === (int) $this->customer_id;
    }

    /**
     * Limits a query to the orders this person is allowed to see: everything if
     * they may read orders at large, otherwise only the ones that are theirs.
     * Theirs needs no permission at all, by design: whoever is planned for a
     * werkbon must be able to open it to do the work.
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

        return $query->assignedTo($user);
    }

    /**
     * The orders that are this person's own, whatever else they may read.
     *
     * Theirs means one of two things, because a werkbon can reach someone along
     * two paths: hij staat als uitvoerder op de werkbon zelf, of er staat een
     * afspraak op de werkbon die hij uitvoert. Wie ergens heen gepland is moet
     * die werkbon kunnen openen — anders staat hij voor de deur zonder te weten
     * wat hij er komt doen. Een geannuleerde afspraak telt niet: daar is hij niet
     * meer voor gepland.
     *
     * The machines a monteur may open from their werkbonnen follow the same rule
     * (User::relevantAssetIds). Checking only the werkbon's own list there let a
     * monteur open the werkbon and its keuring but not the machine on it: planning
     * a second appointment with someone else replaces that list, while the first
     * appointment still names them.
     */
    public function scopeAssignedTo($query, User $user)
    {
        return $query->where(fn ($q) => $q
            ->whereHas('executingUsers', fn ($sq) => $sq->where('users.id', $user->id))
            ->orWhereHas('events', fn ($events) => $events
                ->notCancelled()
                ->whereHas('executingUsers', fn ($sq) => $sq->where('users.id', $user->id))));
    }

    /**
     * The open werkbonnen this person is planned for: there they work on its
     * machines and storingen, and once it is closed that is over.
     */
    public function scopeWorkedOnBy($query, User $user)
    {
        return $query->assignedTo($user)
            ->whereDoesntHave('serviceOrderStage', fn ($stage) => $stage->closesOrder());
    }

    /**
     * Werkbonnen waar het werk op zit maar de administratie nog niet.
     *
     * Drie voorwaarden, en alle drie zijn ze nodig: er is minstens één afspraak
     * geweest, er staat er geen meer in de toekomst, en de fase sluit de werkbon
     * nog niet. Geannuleerde afspraken tellen nergens mee — die zijn niet geweest
     * en komen ook niet meer.
     *
     * whereDoesntHave op de fase pakt ook de werkbonnen zonder fase mee: die is
     * door niets gesloten, dus die hoort er net zo goed bij.
     */
    public function scopeNeedsClosing($query)
    {
        return $query
            ->whereHas('events', fn ($q) => $q->notCancelled())
            ->whereDoesntHave('events', fn ($q) => $q->notCancelled()->where('end', '>=', now()))
            ->whereDoesntHave('serviceOrderStage', fn ($q) => $q->closesOrder());
    }

    /**
     * The werkbonnen the planner lists as ongepland: in a stage that is plannable
     * and not yet planned, and not under a running project, whose bar in the
     * planner is where those are found and planned instead.
     *
     * One definition for the planner's list and for the assistant, so "welke
     * werkbonnen staan er nog ongepland" gets the answer the planner shows.
     * Contract werkbonnen are in it; the planner hides them behind a switch, and
     * whoever reads this decides the same.
     */
    public function scopeAwaitingPlanning($query)
    {
        return $query
            ->whereDoesntHave('project', fn ($q) => $q->openForPlanning())
            ->whereHas('serviceOrderStage', fn ($q) => $q
                ->where('is_plannable_state', true)
                ->where('is_planned_state', false));
    }

    /**
     * Werkbonnen waar de zoekterm ergens in past.
     *
     * Het ene rijtje velden voor elke plek die werkbonnen op tekst zoekt: de
     * werkbonnenlijst, het zoekvenster bovenin en de assistent, en gespiegeld in
     * SERVICE_ORDER_SEARCH_KEYS voor de lijsten bij een klant of project. Zo
     * vindt dezelfde term overal dezelfde werkbonnen. Een monteur telt langs
     * beide wegen die assignedTo() kent: op de werkbon zelf of op een afspraak.
     *
     * Neemt de term zoals hij getypt is, niet als patroon: het nummer moet er
     * nog uit gelezen kunnen worden.
     */
    public function scopeMatchesSearch($query, string $term)
    {
        $like = SearchTerm::like($term);
        $id = self::idFromNumber($term);

        return $query->where(fn ($q) => $q
            ->where('description', 'like', $like)
            ->orWhere('external_purchaseorder_no', 'like', $like)
            ->orWhere('external_invoice_no', 'like', $like)
            ->orWhereHas('customer', fn ($cq) => $cq
                ->where('name', 'like', $like)
                ->orWhere('city', 'like', $like))
            ->orWhere(fn ($lq) => $lq->locationMatchesText($like))
            ->orWhereHas('serviceOrderStage', fn ($sq) => $sq->where('name', 'like', $like))
            ->orWhereHas('executingUsers', fn ($uq) => $uq->where('users.name', 'like', $like))
            ->orWhereHas('events.executingUsers', fn ($uq) => $uq->where('users.name', 'like', $like))
            ->when($id !== null, fn ($nq) => $nq->orWhere('id', $id)));
    }

    /**
     * Werkbonnen waarvan het adres op de zoekterm past.
     *
     * Waar een werkbon uitgevoerd wordt staat op drie plekken: als gekoppelde
     * locatie van de klant, als los ingetypt uitvoeringsadres, of — als de
     * werkbon zelf geen adres heeft — op het project waar hij onder valt. Wie op
     * een straat zoekt hoort ze alle drie te vinden, anders bepaalt de manier van
     * invoeren of een werkbon boven komt. Het project telt alleen mee waar
     * locationWithSource() er ook op terugvalt, zodat een werkbon met een eigen
     * adres niet gevonden wordt op het adres van zijn project.
     *
     * @param  string  $like  Patroon uit SearchTerm::like(), dus met de jokertekens er al uit.
     */
    public function scopeLocationMatchesText($query, string $like)
    {
        return $query->where(fn ($q) => $q
            ->whereHas('linkedLocation', fn ($lq) => $lq->matchesText($like))
            ->orWhere('execution_location', 'like', $like)
            ->orWhere(fn ($pq) => $pq
                ->whereNull('location_id')
                ->where(fn ($nq) => $nq->whereNull('execution_location')->orWhere('execution_location', ''))
                ->whereHas('project', fn ($prq) => $prq
                    ->where('location', 'like', $like)
                    ->orWhereHas('linkedLocation', fn ($lq) => $lq->matchesText($like)))));
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

    /**
     * De weg terug: het id uit een nummer zoals iemand het intikt -- 272, #272,
     * WB-0272, of "Order 272" zoals de planner het toont. Null als er geen
     * nummer staat.
     */
    public static function idFromNumber(string $term): ?int
    {
        return preg_match('/^(?:#|wb-?|order)?\s*(\d+)$/i', trim($term), $match)
            ? (int) $match[1]
            : null;
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

        $project_location = $this->relationLoaded('project') ? $this->project?->addressLine() : null;

        return !empty($project_location)
            ? ['address' => $project_location, 'source' => 'project']
            : ['address' => null, 'source' => null];
    }

    public function getResolvedLocationAttribute(): ?string
    {
        return $this->locationWithSource()['address'];
    }

    /**
     * Its creation and removal are news on the customer's timeline; every edit
     * after that stays on its own.
     */
    public function historyTimelines(string $action): array
    {
        return in_array($action, ['created', 'deleted'], true)
            ? [$this->customer, $this->project]
            : [];
    }

    public function historyLabel(): ?string
    {
        return 'Werkbon ' . $this->number;
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function linkedLocation()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /** The project step this werkbon counts towards; closing all of them reaches it. */
    public function projectMilestone()
    {
        return $this->belongsTo(ProjectMilestone::class);
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

    /**
     * Photos shared from the project this work order falls under, in the order they
     * were shared. They are found through the project, not linked to the work
     * order, so a work order made after the share sees them too. The share row
     * comes along as `share`, with what this side may do with the photo.
     */
    public function sharedImages(): BelongsToMany
    {
        return $this->belongsToMany(Image::class, 'shares', 'source_id', 'shareable_id', 'project_id')
            ->wherePivot('source_type', Project::class)
            ->wherePivot('shareable_type', Image::class)
            ->using(Share::class)
            ->as('share')
            ->withPivot('access')
            ->orderBy('shares.id');
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

    /**
     * Where this werkbon goes back to when it is reopened: the last open fase it
     * was in, read from its fase changes, or the first open fase when they do not
     * say. A werkbon is never reopened into no fase at all.
     */
    public function reopenStage(): ?ServiceOrderStage
    {
        $open_stages = ServiceOrderStage::orderBy('order')->get()
            ->reject(fn (ServiceOrderStage $stage) => $stage->closesOrder())
            ->keyBy('id');

        $reached = $this->activities()
            ->where('event_key', ServiceOrderStageChanged::key())
            ->orderByDesc('activities.id')
            ->pluck('metadata')
            ->flatMap(fn (?array $change) => [$change['new_stage_id'] ?? null, $change['previous_stage_id'] ?? null])
            ->filter()
            ->map(fn ($stage_id) => (int) $stage_id);

        $last_open = $reached->first(fn (int $stage_id) => $open_stages->has($stage_id));

        return $last_open !== null ? $open_stages->get($last_open) : $open_stages->first();
    }

    /** Whether a visit is planned on this werkbon: an appointment that goes ahead. */
    public function hasAppointment(): bool
    {
        return $this->events()->notCancelled()->exists();
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
     * Repoints the order at another customer. The linked location and contact
     * person are dropped along the way: both belong to a customer, so the old
     * ones are by definition unreachable from the new one. The project link is
     * deliberately kept — unlinking it is a bigger decision than this move gets
     * to make.
     */
    public function moveToCustomer(int $customer_id): void
    {
        $previous_customer_id = $this->customer_id;
        $previous = $this->customer?->name;

        $this->update([
            'customer_id' => $customer_id,
            'location_id' => null,
            'contact_id' => null,
        ]);

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
        $cancelled = ServiceOrderStage::planningCancelled();
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
