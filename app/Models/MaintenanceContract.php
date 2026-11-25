<?php

namespace App\Models;

use App\Domain\Signals\Contracts\ContractAssetDetached;
use App\Domain\Signals\ServiceOrders\ServiceOrderUnlinkedFromContract;
use App\Domain\Signals\Signals;
use App\Enums\ContractInterval;
use App\Models\Traits\HasActivities;
use App\Models\Traits\RecordsHistory;
use App\Models\Traits\RemarkableTrait;
use App\Support\Money;
use Carbon\Carbon;
use Database\Factories\MaintenanceContractFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MaintenanceContract extends Model
{
    use HasActivities;

    /** @use HasFactory<MaintenanceContractFactory> */
    use HasFactory;

    use RecordsHistory;
    use RemarkableTrait;

    public const FINANCIAL_ATTRIBUTES = [
        'price',
        'price_interval',
        'price_interval_days',
        'one_time_price',
        'price_formula_inputs',
    ];

    protected array $activity_labels = [
        'customer_id' => 'Klant',
        'title' => 'Titel',
        'start_date' => 'Startdatum',
        'end_date' => 'Einddatum',
        'price' => 'Prijs',
        'price_interval' => 'Prijsinterval',
        'price_interval_days' => 'Prijsinterval (dagen)',
        'one_time_price' => 'Eenmalige kosten',
        'frequency' => 'Frequentie',
        'frequency_days' => 'Frequentie (dagen)',
        'auto_generate' => 'Automatisch genereren',
        'auto_generate_interval' => 'Genereerinterval',
        'auto_generate_interval_days' => 'Genereerinterval (dagen)',
        'manage_frequency_per_asset' => 'Frequentie per machine',
    ];

    /** Shown the way the contract screen shows it, not as the column stores it. */
    protected array $activity_formats = [
        'price' => [Money::class, 'euros'],
    ];

    /**
     * Reported by named signals of their own, so the automatic trail must not
     * repeat them. Enforced by NoDoubleLoggingTest.
     */
    protected array $activity_ignore = [
        'customer_id',
        'cancelled_at',
        'auto_generate',
        'manage_frequency_per_asset',
        'one_time_price',
        'price_formula_version_id',
        'price_formula_inputs',
    ];

    protected array $activity_relations = [
        'customer_id' => ['customer', 'name'],
    ];

    /** Fields whose entries are gated behind a permission. */
    protected array $activity_permissions = [
        'price' => 'maintenancecontract.see_financials',
        'price_interval' => 'maintenancecontract.see_financials',
        'price_interval_days' => 'maintenancecontract.see_financials',
        'one_time_price' => 'maintenancecontract.see_financials',
    ];

    protected $fillable = [
        'customer_id',
        'title',
        'start_date',
        'end_date',
        'price',
        'price_interval',
        'price_interval_days',
        'manage_frequency_per_asset',
        'frequency',
        'frequency_days',
        'auto_generate',
        'auto_generate_interval',
        'auto_generate_interval_days',
    ];

    /**
     * Hidden by default because a contract travels along on the customer page.
     * The contract pages make them visible for whoever may see them; their
     * changes still reach the trail, gated by $activity_permissions.
     */
    protected $hidden = self::FINANCIAL_ATTRIBUTES;

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'price' => 'decimal:2',
        'one_time_price' => 'decimal:2',
        'price_formula_inputs' => 'array',
        'price_interval' => ContractInterval::class,
        'manage_frequency_per_asset' => 'boolean',
        'frequency' => ContractInterval::class,
        'cancelled_at' => 'datetime',
        'auto_generate' => 'boolean',
        'auto_generate_interval' => ContractInterval::class,
    ];

    protected $appends = ['display_title', 'status'];

    protected static function booted(): void
    {
        /**
         * Its werkbonnen and its machines are told first, while they can still be
         * read: the foreign key clears a werkbon's column unseen, and a machine's
         * link goes with the pivot rows below without a word. Each werkbon hears it
         * is no longer the contract's work, each machine that it is no longer on
         * the contract. Then the contract's own pivot rows go, its machine links
         * and its links to the trail among them; its "Verwijderd" lands on the
         * customer.
         */
        static::deleting(function (MaintenanceContract $contract) {
            $contract->generatedServiceOrders()->get()->each(fn (ServiceOrder $order) => Signals::dispatch(
                new ServiceOrderUnlinkedFromContract(
                    $order,
                    $contract,
                    $contract->display_title,
                    'door verwijdering van het contract',
                    contract_deleted: true,
                ),
            ));

            $contract->assets()->get()->each(fn (Asset $asset) => Signals::dispatch(
                new ContractAssetDetached($contract, $asset, contract_deleted: true),
            ));

            $id = $contract->id;
            $morph_class = MaintenanceContract::class;
            $pivot_tables = [
                'assetables' => 'assetable',
                'activityables' => 'activityable',
                'remarkables' => 'remarkable',
                'documentables' => 'documentable',
            ];

            foreach ($pivot_tables as $table => $morph) {
                DB::table($table)
                    ->where("{$morph}_type", $morph_class)
                    ->where("{$morph}_id", $id)
                    ->delete();
            }
        });
    }

    /**
     * The version of a price formula this contract is priced by. While there is
     * one, it sets the price; the contract stays on it until moved to a newer one.
     */
    public function priceFormulaVersion()
    {
        return $this->belongsTo(PriceFormulaVersion::class);
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
        return 'maintenancecontract.read';
    }

    public function historyLabel(): ?string
    {
        return 'Contract ' . $this->display_title;
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function documents()
    {
        return $this->morphToMany(Document::class, 'documentable')->withTimestamps();
    }

    public function assets()
    {
        return $this->morphToMany(Asset::class, 'assetable')
            ->withPivot(['id', 'frequency', 'frequency_days', 'last_generated_at'])
            ->withTimestamps();
    }

    public function getLocationsAttribute()
    {
        return $this->assets->map->linkedLocation->filter()->unique('id')->values();
    }

    /**
     * Running today: not cancelled, already started and not yet ended. Mirrors
     * the 'actief' branch of the status attribute in query form.
     */
    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();
        $end_date = $query->qualifyColumn('end_date');

        return $query->whereNull($query->qualifyColumn('cancelled_at'))
            ->where($query->qualifyColumn('start_date'), '<=', $today)
            ->where(fn ($q) => $q->whereNull($end_date)->orWhere($end_date, '>=', $today));
    }

    /**
     * Not cancelled and not past its end date: running now or still to start.
     * An ended contract keeps the price it ended with.
     */
    public function scopeNotEnded(Builder $query): Builder
    {
        $end_date = $query->qualifyColumn('end_date');

        return $query->whereNull($query->qualifyColumn('cancelled_at'))
            ->where(fn ($q) => $q->whereNull($end_date)->orWhere($end_date, '>=', now()->toDateString()));
    }

    /**
     * Still to start: not cancelled, with a start date after today. The
     * 'toekomstig' branch of the status attribute in query form.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('cancelled_at'))
            ->where($query->qualifyColumn('start_date'), '>', now()->toDateString());
    }

    /**
     * Run out: not cancelled, started, and past its end date. The 'verlopen'
     * branch of the status attribute in query form.
     */
    public function scopeExpired(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->whereNull($query->qualifyColumn('cancelled_at'))
            ->where($query->qualifyColumn('start_date'), '<=', $today)
            ->where($query->qualifyColumn('end_date'), '<', $today);
    }

    /** Cancelled, whatever its dates: the 'geannuleerd' branch of the status attribute. */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->whereNotNull($query->qualifyColumn('cancelled_at'));
    }

    /**
     * Contracts with this status attribute, by query rather than by loading
     * every contract and reading it off each one.
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'actief' => $query->active(),
            'toekomstig' => $query->upcoming(),
            'verlopen' => $query->expired(),
            'geannuleerd' => $query->cancelled(),
            default => throw new InvalidArgumentException('Onbekende contractstatus "' . $status . '": kies actief, toekomstig, verlopen of geannuleerd.'),
        };
    }

    /**
     * What the contract comes to a year: its price, or the price given, times
     * the periods its price interval has in a year, rounded to the cent once.
     */
    public function annualPrice(?float $price = null): float
    {
        return self::annualAmount($price ?? (float) $this->price, $this->price_interval?->value, $this->price_interval_days);
    }

    /**
     * The rule annualPrice() applies, for a price and interval read without a
     * model: a sum over many contracts grouped in the query comes to what
     * adding up each contract's annualPrice() would.
     */
    public static function annualAmount(float $price, ?string $price_interval, ?int $price_interval_days): float
    {
        return round($price * ContractInterval::timesPerYear($price_interval, $price_interval_days), 2);
    }

    public function hasEnded(): bool
    {
        return in_array($this->status, ['geannuleerd', 'verlopen'], true);
    }

    /**
     * How often a machine on this contract really gets a visit: the interval
     * werkbonnen are generated at when one is set, else the machine's own
     * frequency or the contract's. Generating and pricing both go by this.
     *
     * @return array{0: ?string, 1: ?int}
     */
    public function effectiveFrequency(?object $pivot = null): array
    {
        if ($this->auto_generate_interval) {
            $interval = $this->auto_generate_interval instanceof ContractInterval
                ? $this->auto_generate_interval->value
                : $this->auto_generate_interval;

            return [$interval, $this->auto_generate_interval_days];
        }

        if ($this->manage_frequency_per_asset && $pivot !== null) {
            return [$pivot->frequency, $pivot->frequency_days];
        }

        $frequency = $this->frequency instanceof ContractInterval ? $this->frequency->value : $this->frequency;

        return [$frequency, $this->frequency_days];
    }

    public function generatedServiceOrders()
    {
        return $this->hasMany(ServiceOrder::class)->orderByDesc('order_date')->orderByDesc('id');
    }

    public function getDisplayTitleAttribute(): string
    {
        if (!empty($this->attributes['title'])) {
            return $this->attributes['title'];
        }

        $customer_name = $this->customer?->name ?? 'Onbekende klant';
        $start = $this->start_date ? Carbon::parse($this->start_date)->format('d-m-Y') : '?';
        $end = $this->end_date ? Carbon::parse($this->end_date)->format('d-m-Y') : 'heden';

        return $customer_name . ' — ' . $start . ' t/m ' . $end;
    }

    public function getStatusAttribute(): string
    {
        if ($this->cancelled_at !== null) {
            return 'geannuleerd';
        }

        $today = Carbon::today();
        $start = $this->start_date ? Carbon::parse($this->start_date) : null;
        $end = $this->end_date ? Carbon::parse($this->end_date) : null;

        if ($start && $today->lt($start)) {
            return 'toekomstig';
        }

        if ($end && $today->gt($end)) {
            return 'verlopen';
        }

        return 'actief';
    }
}
