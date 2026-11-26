<?php

namespace App\Models;

use App\Models\Traits\HasMaterials;
use App\Models\Traits\RecordsHistory;
use App\Services\MateriableService;
use App\Services\TaskInstanceSerialSlotService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceOrderTaskInstance extends Model
{
    use HasFactory;
    use HasMaterials;

    /**
     * Covers a task deleted on its own. A task cascaded away with its service order never
     * gets here, so ServiceOrder::deleting releases those before the database takes them.
     */
    protected static function booted(): void
    {
        static::deleting(function (ServiceOrderTaskInstance $task_instance) {
            app(MateriableService::class)->release(
                $task_instance,
                'verwijdering taak ' . $task_instance->timelineTitle(),
                announce_lines: true,
            );

            $task_instance->productables()->delete();
        });
    }

    use RecordsHistory;

    protected array $activity_labels = [
        'service_order_id' => 'Werkbon',
        'service_order_task_id' => 'Taaksjabloon',
        'product_id' => 'Product',
        'completed_by' => 'Afgerond door',
        'signed_by' => 'Ondertekend door',
        'signed_at' => 'Ondertekend op',
        'title' => 'Titel',
        'quantity' => 'Aantal',
        'checks_per_unit' => 'Checks per machine',
        'is_complete' => 'Afgerond',
        'is_cancelled' => 'Geannuleerd',
        'completed_at' => 'Afgerond op',
        'description' => 'Omschrijving',
        'cancellation_reason' => 'Reden annulering',
    ];

    /**
     * Completing, signing and cancelling a task are told by their own signals
     * (TaskCompletionChanged, TaskSigned, TaskSignatureRemoved, TaskCancelled), in
     * a sentence that names the task. Logging the columns as well says it twice.
     */
    protected array $activity_ignore = [
        'signature_base64',
        'is_complete',
        'completed_at',
        'completed_by',
        'signed_by',
        'signed_at',
        'is_cancelled',
        'cancellation_reason',
    ];

    protected $fillable = [
        'service_order_id',
        'service_order_task_id',
        'product_id',
        'quantity',
        'checks_per_unit',
        'title',
        'description',
        'is_complete',
        'completed_at',
        'completed_by',
        'signed_by',
        'signature_base64',
        'signed_at',
        'is_cancelled',
        'cancellation_reason',
    ];

    protected $casts = [
        'is_complete' => 'boolean',
        'quantity' => 'integer',
        'checks_per_unit' => 'boolean',
        'completed_at' => 'datetime',
        'signed_at' => 'datetime',
        'is_cancelled' => 'boolean',
    ];

    /** A task has no screen of its own; its history is read on the work order. */
    public function historyTimelines(string $action): array
    {
        return [$this->serviceOrder];
    }

    /** A task with neither a title nor a description is just "Taak", not Taak "Taak". */
    public function historyLabel(): ?string
    {
        $title = $this->ownTitle();

        return $title === null ? 'Taak' : 'Taak "' . $title . '"';
    }

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /** The tasks this person may see: on a werkbon they may see, and meant for them there. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn($query->qualifyColumn('service_order_id'), ServiceOrder::visibleTo($user)->select('service_orders.id'))
            ->seenBy($user);
    }

    /**
     * Wie welke werkbontaak te zien krijgt, los van of hij de werkbon mag zien.
     *
     * Een taak zonder rollen is van iedereen. Een taak met rollen is van de mensen die
     * de werkbon in een van die rollen uitvoeren, en die rollen staan bij de afspraak,
     * niet bij de gebruiker: dezelfde monteur kan de ene bon als elektricien draaien en
     * de andere als installateur. Een geannuleerde afspraak telt niet mee.
     *
     * TaskInstanceVisibility vraagt dit voor het scherm en voor het weigeren van het
     * sluiten, zodat er één regel is.
     */
    public function scopeSeenBy(Builder $query, User $user): Builder
    {
        if (self::seesEveryTask($user)) {
            return $query;
        }

        $their_roles_here = DB::table('user_role_userable')
            ->join('userables', 'userables.id', '=', 'user_role_userable.userable_id')
            ->where('userables.type', 'executing')
            ->where('userables.user_id', $user->id)
            ->where('userables.userable_type', (new Event)->getMorphClass())
            ->whereIn('userables.userable_id', Event::query()
                ->notCancelled()
                ->join('eventables', 'eventables.event_id', '=', 'events.id')
                ->where('eventables.eventable_type', (new ServiceOrder)->getMorphClass())
                ->whereColumn('eventables.eventable_id', $this->qualifyColumn('service_order_id'))
                ->select('events.id'))
            ->select('user_role_userable.user_role_id');

        return $query->where(fn (Builder $tasks) => $tasks
            ->whereDoesntHave('userRoles')
            ->orWhereHas('userRoles', fn (Builder $roles) => $roles->whereIn('user_roles.id', $their_roles_here)));
    }

    /** Whoever sees every task on a werkbon, whatever role it asks for. */
    public static function seesEveryTask(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('serviceorder.see_all_task_instances');
    }

    public function serviceOrderTask()
    {
        return $this->belongsTo(ServiceOrderTask::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function userRoles()
    {
        return $this->morphToMany(UserRole::class, 'userroleable')->withTimestamps();
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by')->withTrashed();
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function checks()
    {
        return $this->hasMany(ServiceOrderTaskInstanceCheck::class)
            ->orderBy('unit_index')
            ->orderBy('sort_order');
    }

    /**
     * The aantallen filled in for the bundle parts that leave theirs open — one omvormer
     * with however many panelen this roof takes. A bundle part with a fixed aantal is
     * settled by the product and has no row here.
     */
    public function productables()
    {
        return $this->morphMany(Productable::class, 'productable');
    }

    public function getEffectiveDescriptionAttribute(): string
    {
        return $this->description ?? $this->serviceOrderTask?->description ?? '';
    }

    public function getEffectiveTitleAttribute(): string
    {
        return $this->title ?: ($this->serviceOrderTask?->title ?: '');
    }

    /**
     * How the task is named on the timeline: its own title, its template's, or for a
     * task typed by hand the start of its description.
     */
    public function timelineTitle(): string
    {
        return $this->ownTitle() ?? 'Taak';
    }

    private function ownTitle(): ?string
    {
        return ($this->effective_title ?: Str::limit((string) $this->description, 60)) ?: null;
    }

    /**
     * The machines this task is expected to deliver and the ones registered so far.
     * Not appended by default: it walks the product's bundle tree, so only the pages
     * that show the serial drawer ask for it.
     *
     * @return array<int, array{product_id: int, label: string, expected: int,
     *                          assets: array<int, array{id: int, serial_number: ?string}>}>
     */
    public function getSerialSlotsAttribute(): array
    {
        return app(TaskInstanceSerialSlotService::class)->groups($this);
    }
}
