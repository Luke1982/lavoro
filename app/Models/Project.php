<?php

namespace App\Models;

use App\Enums\ProjectStatuses;
use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use App\Support\ProjectNumbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Project extends Model
{
    use HasActivities;
    use RecordsHistory;

    public const FINANCIAL_ATTRIBUTES = [
        'financial_notes',
        'financial_notes_updated_at',
        'financial_notes_updated_by',
        'budget_amount',
    ];

    protected array $activity_labels = [
        'number' => 'Projectnummer',
        'title' => 'Titel',
        'description' => 'Omschrijving',
        'customer_id' => 'Klant',
        'project_manager_id' => 'Projectleider',
        'contact_id' => 'Contactpersoon',
        'status' => 'Status',
        'start_date' => 'Startdatum',
        'end_date' => 'Einddatum',
        'location' => 'Vrije locatie',
        'location_id' => 'Locatie',
        'budget_hours' => 'Begrote uren',
        'budget_amount' => 'Begroot bedrag',
    ];

    protected array $activity_relations = [
        'customer_id' => ['customer', 'name'],
        'project_manager_id' => ['projectManager', 'name'],
        'contact_id' => ['contact', 'full_name'],
        'location_id' => ['linkedLocation', 'title'],
    ];

    /** The status is reported by ProjectStatusChanged, which says why it moved. */
    protected array $activity_ignore = [
        'status',
    ];

    /** The budget is money: only who manages a project's finances reads its history. */
    protected array $activity_permissions = [
        'budget_amount' => 'project.manage_financials',
    ];

    protected $fillable = [
        'title',
        'description',
        'location',
        'location_id',
        'start_date',
        'end_date',
        'customer_id',
        'project_manager_id',
        'contact_id',
        'status',
        'budget_hours',
        'budget_amount',
        'financial_notes',
        'financial_notes_updated_at',
        'financial_notes_updated_by',
    ];

    /**
     * Alleen wie de financiën beheert mag ze zien, en een project wordt op veel
     * pagina's meegestuurd; een pagina die ze nodig heeft maakt ze zichtbaar.
     * Verborgen velden komen ook niet in de tijdlijn: de notities zijn een
     * spreadsheet die bij elke celwijziging in zijn geheel wordt opgeslagen.
     */
    protected $hidden = self::FINANCIAL_ATTRIBUTES;

    protected $appends = ['is_closed'];

    protected function casts(): array
    {
        return [
            'financial_notes' => 'array',
            'financial_notes_updated_at' => 'datetime',
            'budget_hours' => 'float',
            'budget_amount' => 'float',
        ];
    }

    /**
     * The number is given when the project is made and never changes: it is what a
     * customer or a contractor quotes back on the phone.
     */
    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            if (blank($project->number)) {
                $project->number = ProjectNumbers::next(
                    (int) Carbon::parse($project->created_at ?? now())->format('Y')
                );
            }
        });
    }

    /**
     * Projecten met een looptijd: alleen die krijgen een balk in de planner.
     */
    /**
     * What a werkbon or appointment needs of its project to name it and say where
     * the work happens, for spreading into a with(): the columns, and the
     * customer location picked for it. Loading fewer columns quietly drops the
     * picked location and sends the monteur to the customer's head office.
     *
     * @return array<int, string>
     */
    public static function relationsForAddress(string $path): array
    {
        return [$path . ':id,number,title,status,location,location_id', $path . '.linkedLocation'];
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->whereNotNull('start_date')->whereNotNull('end_date');
    }

    /**
     * Numbers are handed out in the order projects are made, so that order is the
     * number order. Sorting the number itself as text would put P-2026-1000 before
     * P-2026-999.
     */
    public function scopeInNumberOrder(Builder $query, string $direction = 'desc'): Builder
    {
        return $query->orderBy('created_at', $direction)->orderBy('id', $direction);
    }

    /** Projects still running: not finished and not called off. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', ProjectStatuses::closedValues());
    }

    /**
     * Projecten waarvan de balk nog niet voorbij is en die nog lopen. Alleen daaronder
     * vindt de planner een werkbon terug; die van een afgelopen, afgeronde of
     * geannuleerde project, of van een project zonder looptijd, horen in de lijst met
     * ongeplande werkbonnen.
     */
    public function scopeOpenForPlanning(Builder $query): Builder
    {
        return $query->scheduled()->active()->whereDate('end_date', '>=', today());
    }

    /**
     * Everyone who may read projects reads all of them, exactly as the policy says;
     * the scope exists so a query over history or search can ask the same question.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $user && $user->can('viewAny', self::class)
            ? $query
            : $query->whereRaw('0 = 1');
    }

    public function scopeMatchesSearch(Builder $query, string $like): Builder
    {
        return $query->where(fn ($q) => $q
            ->where('title', 'like', $like)
            ->orWhere('number', 'like', $like)
            ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', $like))
            ->orWhereHas('projectManager', fn ($uq) => $uq->where('name', 'like', $like)));
    }

    public function statusEnum(): ?ProjectStatuses
    {
        return ProjectStatuses::tryFrom((string) $this->status);
    }

    public function isClosed(): bool
    {
        return $this->statusEnum()?->isClosed() === true;
    }

    public function getIsClosedAttribute(): bool
    {
        return $this->isClosed();
    }

    /** Why this project takes no new werkbon, or null when it does. */
    public function refusesNewWork(): ?string
    {
        return $this->isClosed()
            ? 'Project ' . $this->label() . ' is ' . mb_strtolower($this->status)
                . '. Zet het terug op Gestart om er nog een werkbon onder te zetten.'
            : null;
    }

    /** Where the work happens: the customer's location picked for it, or what was typed. */
    public function addressLine(): ?string
    {
        $linked = $this->linkedLocation?->addressLine();

        return filled($linked) ? $linked : (filled($this->location) ? $this->location : null);
    }

    public function label(): string
    {
        return trim(($this->number ? $this->number . ' ' : '') . $this->title);
    }

    /**
     * Its creation and removal are news on the customer's timeline; every edit
     * after that stays on its own.
     */
    public function historyTimelines(string $action): array
    {
        return in_array($action, ['created', 'deleted'], true) ? [$this->customer] : [];
    }

    public function historyPermission(): ?string
    {
        return 'project.read';
    }

    public function historyLabel(): ?string
    {
        return 'Project ' . $this->label();
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function financialNotesUpdatedBy()
    {
        return $this->belongsTo(User::class, 'financial_notes_updated_by');
    }

    public function projectManager()
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function linkedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function milestones()
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('projected_date');
    }

    public function serviceOrders()
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function documents()
    {
        return $this->morphToMany(Document::class, 'documentable')->withTimestamps();
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main'])
            ->withTimestamps();
    }
}
