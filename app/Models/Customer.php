<?php

namespace App\Models;

use App\Models\Traits\HasCustomFields;
use App\Models\Traits\RecordsHistory;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasCustomFields;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use RecordsHistory;

    protected array $activity_labels = [
        'quotes_email' => 'Offerte e-mail',
        'website' => 'Website',
        'country' => 'Land',
        'postal_address' => 'Postadres',
        'postal_postal_code' => 'Postcode postadres',
        'postal_city' => 'Plaats postadres',
        'postal_country' => 'Land postadres',
        'chamber_of_commerce_number' => 'KvK-nummer',
        'location_code' => 'Locatiecode',
        'billing_customer_id' => 'Factuurklant',
        'name' => 'Naam',
        'email' => 'E-mail',
        'invoice_email' => 'Factuur e-mail',
        'phone' => 'Telefoon',
        'mobile' => 'Mobiel',
        'address' => 'Adres',
        'postal_code' => 'Postcode',
        'city' => 'Plaats',
        'iban' => 'IBAN',
        'vat_number' => 'BTW-nummer',
        'contactname' => 'Contactpersoon',
    ];

    protected array $activity_ignore = [
        'lat',
        'lon',
        'snelstart_id',
    ];

    /** Fields whose entries are gated behind a permission. */
    protected array $activity_permissions = [
        'iban' => 'customer.see_sensitive',
        'vat_number' => 'customer.see_sensitive',
    ];

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
        $ids = Asset::treeIdsForCustomers([$this->id]);

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

    /**
     * Storingen on the root machines only. Machines inside a bundle carry no customer_id,
     * so their storingen fall outside this relation — use ticketTree() for the whole tree.
     */
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

    /**
     * Every storing on this customer's machines at any depth. A machine hanging in a
     * bundle carries no customer_id of its own, so tickets() drops its storingen by
     * construction — use this wherever the whole tree is meant.
     *
     * @param  array<int, string>  $with
     * @return Collection<int, Ticket>
     */
    public function ticketTree(array $with = []): Collection
    {
        return Ticket::query()
            ->whereIn('asset_id', Asset::treeIdsForCustomers([$this->id]))
            ->with($with)
            ->orderByDesc('created_at')
            ->get();
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

    /**
     * Above this many customers a combobox stops holding them all and searches
     * server-side instead, through ComboSearchController.
     */
    public const COMBO_AJAX_THRESHOLD = 50;

    /**
     * The shape a customer combobox expects. The city rides along in the name so
     * two Jansens can be told apart, and the bare name rides along beside it for
     * whoever needs the customer's actual name rather than the list label.
     *
     * @return array{id: int, name: string, plain_name: string}
     */
    public function toComboOption(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->city ? $this->name . ' – ' . $this->city : $this->name,
            'plain_name' => $this->name,
        ];
    }
}
