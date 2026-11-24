<?php

namespace App\Models;

use App\Actions\Contracts\DetachContractAssetAction;
use App\Enums\AssetStatusses;
use App\Enums\ContractAssetFilter;
use App\Enums\EventStatusses;
use App\Enums\ServiceJobOutcomes;
use App\Enums\TicketStatusses;
use App\Models\Traits\HasActivities;
use App\Models\Traits\HasCustomFields;
use App\Models\Traits\RecordsHistory;
use Database\Factories\AssetsFactory;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Asset extends Model
{
    use HasActivities;
    use HasCustomFields;

    /** @use HasFactory<AssetsFactory> */
    use HasFactory;

    use RecordsHistory;

    protected array $activity_labels = [
        'product_relation_id' => 'Productrelatie',
        'productable_id' => 'Gekoppeld product',
        'service_order_task_instance_id' => 'Taak',
        'product_id' => 'Product',
        'customer_id' => 'Klant',
        'location_id' => 'Locatie',
        'parent_asset_id' => 'Bovenliggende machine',
        'serial_number' => 'Serienummer',
        'next_service_date' => 'Volgende servicedatum',
        'date_in_service' => 'In dienst sinds',
        'status' => 'Status',
    ];

    /** Stored as plain dates; a cast would change how every screen receives them. */
    protected array $activity_dates = [
        'next_service_date',
        'date_in_service',
    ];

    protected array $activity_relations = [
        'customer_id' => ['customer', 'name'],
        'location_id' => ['linkedLocation', 'title'],
        'product_id' => ['product', 'display_name'],
    ];

    /**
     * Reported by named signals of their own: a machine only changes customer or
     * parent by being transferred, or by being hung under another machine or
     * taken out of one. Enforced by NoDoubleLoggingTest.
     */
    protected array $activity_ignore = [
        'customer_id',
        'parent_asset_id',
        'product_relation_id',
        'productable_id',
    ];

    protected $fillable = [
        'product_id',
        'customer_id',
        'location_id',
        'parent_asset_id',
        'product_relation_id',
        'productable_id',
        'service_order_task_instance_id',
        'serial_number',
        'next_service_date',
        'date_in_service',
        'status',
    ];

    /**
     * Mirrors the chk_asset_owner CHECK constraint, which only exists on MySQL because
     * SQLite cannot add a constraint to an existing table. Keeping the rule here as well
     * means it holds on every driver and stays covered by the test suite.
     */
    protected static function booted(): void
    {
        /**
         * What hangs off it goes first, one by one, while it can still be read; the
         * database would cascade all of it away unseen. Its storingen, each
         * "Verwijderd" told on the customer and on the werkbon it was on; its
         * keuringen, each told on the werkbon it was for; its parts, each told on
         * the customer and each taken off its own contracts in turn. Then it is
         * taken off its contracts the way the contract screen does it, told on
         * each contract, which reprices when a formula prices it. The database
         * would drop that link by itself, but without a word.
         *
         * Last go its own links to the trail, as a deleted contract's and quote's
         * do: what was told on the machine alone has no page left to be read on,
         * and whatever was also told elsewhere stays there. The machine's own
         * "Verwijderd" lands on its customer.
         */
        static::deleting(function (Asset $asset) {
            $asset->tickets()->get()->each(fn (Ticket $ticket) => $ticket->delete());
            $asset->servicejobs()->get()->each(fn (ServiceJob $service_job) => $service_job->delete());
            $asset->childAssets()->get()->each(fn (Asset $child) => $child->delete());

            DB::table('assetables')
                ->where('asset_id', $asset->id)
                ->where('assetable_type', MaintenanceContract::class)
                ->get(['id', 'assetable_id'])
                ->each(function ($pivot) {
                    $contract = MaintenanceContract::find($pivot->assetable_id);

                    if ($contract) {
                        app(DetachContractAssetAction::class)->execute($contract, $pivot->id);
                    }
                });

            DB::table('activityables')
                ->where('activityable_type', Asset::class)
                ->where('activityable_id', $asset->id)
                ->delete();
        });

        static::saving(function (Asset $asset) {
            if (($asset->customer_id === null) === ($asset->parent_asset_id === null)) {
                throw new DomainException(
                    'Een machine hoort óf bij een klant óf bij een bovenliggende machine, niet bij beide.'
                );
            }
        });
    }

    /** A machine's history is part of its customer's story. */
    public function historyTimelines(string $action): array
    {
        return [$this->resolvedCustomer()];
    }

    public function historyLabel(): ?string
    {
        $name = $this->product?->display_name ?? 'Machine #' . $this->id;

        return $this->serial_number ? $name . ' (serienummer ' . $this->serial_number . ')' : $name;
    }

    /**
     * Limits a query to the machines this person may see, mirroring AssetPolicy:
     * everything with asset.read, otherwise only the machines on their own open
     * werkbonnen, and nothing at all without either.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasPermission('asset.read')) {
            return $query;
        }

        if ($user->hasPermission('asset.read.relevant.serviceorder')) {
            return $query->whereIn('id', $user->relevantAssetIds());
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopeUpcomingAndUnplanned($query, int $days = 60)
    {
        return $query->whereBetween('next_service_date', [now(), now()->addDays($days)])
            ->where('status', '!=', AssetStatusses::inactive->value)
            ->unplanned();
    }

    /**
     * Machines waiting on an open keuring whose werkbon has an afspraak still to come.
     */
    public function scopePlanned($query)
    {
        return $query->whereHas('servicejobs', fn (Builder $jobs) => self::constrainToPlannedJobs($jobs));
    }

    public function scopeUnplanned($query)
    {
        return $query->whereDoesntHave('servicejobs', fn (Builder $jobs) => self::constrainToPlannedJobs($jobs));
    }

    private static function constrainToPlannedJobs(Builder $jobs): void
    {
        $jobs->whereNull('completed_on')
            ->whereHas('serviceOrder', fn (Builder $order) => $order
                ->where('status', '!=', 'closed')
                ->whereHas('events', fn (Builder $events) => $events
                    ->where('status', '!=', EventStatusses::completed->value)
                    ->where('start', '>', now())));
    }

    public function scopeExpired($query)
    {
        return $query->where('next_service_date', '<', now())
            ->where('status', '!=', AssetStatusses::inactive->value);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function serviceOrderTaskInstance()
    {
        return $this->belongsTo(ServiceOrderTaskInstance::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function linkedLocation()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function openTickets()
    {
        return $this->hasMany(Ticket::class)->where('status', 'Open');
    }

    public function pendingTickets()
    {
        return $this->hasMany(Ticket::class)->where('status', 'In behandeling');
    }

    /**
     * The storingen still waiting for a werkbon: open or in behandeling, and on none yet.
     */
    public function plannableTickets()
    {
        return $this->hasMany(Ticket::class)
            ->whereIn('status', [TicketStatusses::open->value, TicketStatusses::in_behandeling->value])
            ->whereNull('service_order_id');
    }

    public function closedTickets()
    {
        return $this->hasMany(Ticket::class)->where('status', 'Gesloten');
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main'])
            ->withTimestamps();
    }

    public function servicejobs()
    {
        return $this->hasMany(ServiceJob::class);
    }

    public function pendingServiceJobs()
    {
        return $this->hasMany(ServiceJob::class)->where('outcome', ServiceJobOutcomes::nog_geen_uitkomst->value);
    }

    public function parentAsset()
    {
        return $this->belongsTo(Asset::class, 'parent_asset_id');
    }

    public function childAssets()
    {
        return $this->hasMany(Asset::class, 'parent_asset_id');
    }

    public function productRelation()
    {
        return $this->belongsTo(ProductRelation::class);
    }

    public function productable()
    {
        return $this->belongsTo(Productable::class);
    }

    /**
     * Walks up to the owning root. A child carries no customer of its own, so every
     * question about who owns it is really a question about the machine it sits in.
     * A parent loaded beforehand is used while it is still the parent, so a list
     * that loads the chain asks nothing per row.
     */
    public function rootAsset(): Asset
    {
        $node = $this;

        while ($node->parent_asset_id !== null) {
            $parent = $node->relationLoaded('parentAsset') ? $node->getRelation('parentAsset') : null;
            $node = $parent !== null && (int) $parent->getKey() === (int) $node->parent_asset_id
                ? $parent
                : $node->parentAsset()->firstOrFail();
        }

        return $node;
    }

    public function resolvedCustomerId(): ?int
    {
        return $this->rootAsset()->customer_id;
    }

    public function resolvedLocationId(): ?int
    {
        return $this->rootAsset()->location_id;
    }

    /**
     * The customer this machine is actually about: itself when it's a root asset,
     * or its root ancestor's customer when it's a part hanging under one.
     */
    public function resolvedCustomer(): ?Customer
    {
        return $this->rootAsset()->customer;
    }

    /**
     * The location this machine is actually at, resolved the same way as resolvedCustomer().
     */
    public function resolvedLinkedLocation(): ?Location
    {
        return $this->rootAsset()->linkedLocation;
    }

    /**
     * Every asset id belonging to any of the given customers, at any depth: the
     * roots plus each descendant hanging under them. Backs Customer::assetTree()
     * and ticket search, so "does this machine ultimately belong to customer X"
     * is answered by one recursive query instead of being reimplemented per caller.
     *
     * @param  array<int, int>  $customer_ids
     * @return array<int, int>
     */
    public static function treeIdsForCustomers(array $customer_ids): array
    {
        if ($customer_ids === []) {
            return [];
        }

        $roots = self::query()->whereIn('customer_id', array_values(array_unique($customer_ids)))->select('id');

        return self::treeIds($roots);
    }

    /**
     * Which of the given assets are on a running maintenance contract, or are a
     * part of a machine that is: a contract is drawn up for a machine, and what
     * is built into it comes along. The contract trees are walked in the
     * database and only the asked ids come back, so the question sets the size
     * of the answer.
     *
     * @param  array<int, int>  $asset_ids
     * @return array<int, int>
     */
    public static function idsUnderActiveContract(array $asset_ids): array
    {
        if ($asset_ids === []) {
            return [];
        }

        return self::treeIds(self::contractRoots(), array_values(array_unique($asset_ids)));
    }

    /**
     * Keeps the assets idsUnderActiveContract() would name, or with $under false leaves
     * them out, inside the query itself, so a list of any length is narrowed before a row
     * is fetched.
     */
    public function scopeUnderActiveContract(Builder $query, bool $under = true): Builder
    {
        $roots = self::contractRoots();

        return $query->whereRaw(
            $query->qualifyColumn('id') . ($under ? ' IN (' : ' NOT IN (') . self::treeSql($roots) . ')',
            $roots->getBindings()
        );
    }

    public function scopeNotUnderActiveContract(Builder $query): Builder
    {
        return $query->underActiveContract(false);
    }

    public function scopeFilteredOnContract(Builder $query, ContractAssetFilter $filter): Builder
    {
        return match ($filter) {
            ContractAssetFilter::only => $query->underActiveContract(),
            ContractAssetFilter::hide => $query->notUnderActiveContract(),
            ContractAssetFilter::mixed => $query,
        };
    }

    /**
     * The running contracts each of the given assets falls under: its own, and those of
     * every machine it is built into. Assets under none are left out.
     *
     * @param  array<int, int>  $asset_ids
     * @return array<int, array<int, array{id: int, display_title: string}>>
     */
    public static function activeContractsCovering(array $asset_ids): array
    {
        if ($asset_ids === []) {
            return [];
        }

        $covered_by = self::treeRows(self::contractRoots(), 'id, root_id', array_values(array_unique($asset_ids)))
            ->groupBy(fn (object $row) => (int) $row->id);

        $contracts_of = self::whereIn('id', $covered_by->flatten()->pluck('root_id')->unique()->values())
            ->with(['maintenanceContracts' => fn ($contracts) => $contracts->active()
                ->select([
                    'maintenance_contracts.id',
                    'maintenance_contracts.customer_id',
                    'maintenance_contracts.title',
                    'maintenance_contracts.start_date',
                    'maintenance_contracts.end_date',
                ])
                ->with('customer:id,name'),
            ])
            ->get(['id'])
            ->mapWithKeys(fn (Asset $root) => [$root->id => $root->maintenanceContracts]);

        return $covered_by
            ->map(fn ($rows) => $rows
                ->flatMap(fn (object $row) => $contracts_of[$row->root_id])
                ->unique('id')
                ->map(fn (MaintenanceContract $contract) => $contract->only(['id', 'display_title']))
                ->values()
                ->all())
            ->all();
    }

    private static function contractRoots(): Builder
    {
        return self::whereHas('maintenanceContracts', fn ($contracts) => $contracts->active())->select('assets.id');
    }

    /**
     * The roots and every asset hanging under them, at any depth; with $only,
     * just those of them. The roots go in as a subquery, never as a list of
     * placeholders, so a large tenant cannot outgrow what MySQL accepts.
     *
     * @param  array<int, int>|null  $only
     * @return array<int, int>
     */
    private static function treeIds(Builder $roots, ?array $only = null): array
    {
        return self::treeRows($roots, 'DISTINCT id', $only)->map(fn (object $row) => (int) $row->id)->all();
    }

    /**
     * @param  array<int, int>|null  $only
     * @return Collection<int, object>
     */
    private static function treeRows(Builder $roots, string $columns, ?array $only = null): Collection
    {
        $filter = $only === null ? '' : ' WHERE id IN (' . implode(',', array_fill(0, count($only), '?')) . ')';

        return collect(DB::select(
            self::treeSql($roots, $columns) . $filter,
            [...$roots->getBindings(), ...($only ?? [])]
        ));
    }

    /**
     * Each asset in the trees with the root it was reached from; an asset under two
     * roots comes back once for each.
     */
    private static function treeSql(Builder $roots, string $columns = 'id'): string
    {
        return 'WITH RECURSIVE asset_tree (id, root_id) AS ('
            . 'SELECT roots.id, roots.id FROM (' . $roots->toSql() . ') AS roots'
            . ' UNION ALL'
            . ' SELECT assets.id, asset_tree.root_id FROM assets'
            . ' INNER JOIN asset_tree ON assets.parent_asset_id = asset_tree.id'
            . ') SELECT ' . $columns . ' FROM asset_tree';
    }

    public function maintenanceContracts()
    {
        return $this->morphedByMany(MaintenanceContract::class, 'assetable')
            ->withPivot(['id', 'frequency', 'frequency_days', 'last_generated_at'])
            ->withTimestamps();
    }
}
