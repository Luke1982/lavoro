<?php

namespace App\Models;

use App\Models\Traits\HasCustomFields;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Customer extends Model
{
    use HasCustomFields;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $fillable = [
        'snelstart_id',
        'name',
        'email',
        'invoice_email',
        'quotes_email',
        'phone',
        'mobile',
        'website',
        'address',
        'postal_code',
        'city',
        'country',
        'postal_address',
        'postal_postal_code',
        'postal_city',
        'postal_country',
        'iban',
        'vat_number',
        'chamber_of_commerce_number',
        'contactname',
        'location_code',
        'billing_customer_id',
        'lat',
        'lon',
    ];

    public ?int $upcoming_asset_days = null;

    /**
     * Root machines only. Children carry no customer_id, so they fall outside this
     * relation by construction — use assetTree() when the whole tree is wanted.
     */
    public function assets()
    {
        return $this->hasMany(Asset::class)->orderBy('next_service_date');
    }

    /**
     * Every machine belonging to this customer at any depth: the roots plus each
     * descendant hanging under them.
     *
     * @param  array<int, string>  $with
     * @return Collection<int, Asset>
     */
    public function assetTree(array $with = []): Collection
    {
        $ids = collect(DB::select(
            'WITH RECURSIVE asset_tree (id) AS ('
            . ' SELECT id FROM assets WHERE customer_id = ?'
            . ' UNION ALL'
            . ' SELECT assets.id FROM assets'
            . ' INNER JOIN asset_tree ON assets.parent_asset_id = asset_tree.id'
            . ') SELECT id FROM asset_tree',
            [$this->id]
        ))->pluck('id');

        return Asset::query()
            ->whereIn('id', $ids)
            ->with($with)
            ->orderBy('next_service_date')
            ->get();
    }

    public function locations()
    {
        return $this->hasMany(Location::class)->orderBy('title');
    }

    public function activeAssets()
    {
        return $this->hasMany(Asset::class)
            ->where('status', 'Actief')
            ->orderBy('next_service_date');
    }

    public function maintenanceContracts()
    {
        return $this->hasMany(MaintenanceContract::class)->orderByDesc('start_date');
    }

    public function upcomingAssets()
    {
        return $this->hasMany(Asset::class)
            ->where('next_service_date', '>=', now())
            ->where('next_service_date', '<=', now()->addDays($this->upcoming_asset_days ?? 30))
            ->where('status', 'Actief')
            ->orderBy('next_service_date');
    }

    public function expiredAssets()
    {
        return $this->hasMany(Asset::class)
            ->where('next_service_date', '<', now())
            ->where('status', 'Actief')
            ->orderBy('next_service_date', 'desc');
    }

    public function tickets()
    {
        return $this->hasManyThrough(
            Ticket::class,
            Asset::class,
            'customer_id',
            'asset_id',
            'id',
            'id'
        );
    }

    public function openTickets()
    {
        $relation = $this->hasManyThrough(
            Ticket::class,
            Asset::class,
            'customer_id',
            'asset_id',
            'id',
            'id'
        );
        $relation->getQuery()->where('tickets.status', 'Open');

        return $relation;
    }

    public function pendingTickets()
    {
        $relation = $this->hasManyThrough(
            Ticket::class,
            Asset::class,
            'customer_id',
            'asset_id',
            'id',
            'id'
        );
        $relation->getQuery()->where('tickets.status', 'In behandeling');

        return $relation;
    }

    public function closedTickets()
    {
        $relation = $this->hasManyThrough(
            Ticket::class,
            Asset::class,
            'customer_id',
            'asset_id',
            'id',
            'id'
        );
        $relation->getQuery()->where('tickets.status', 'Gesloten');

        return $relation;
    }

    public function serviceOrders()
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class)->orderBy('start_date');
    }

    public function billingCustomer()
    {
        return $this->belongsTo(self::class, 'billing_customer_id');
    }

    public function contacts()
    {
        return $this->morphToMany(Contact::class, 'contactable')->withTimestamps();
    }
}
