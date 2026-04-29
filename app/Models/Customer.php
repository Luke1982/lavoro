<?php

namespace App\Models;

use App\Models\Traits\HasCustomFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasFactory;
    use HasCustomFields;

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

    public int|null $upcoming_asset_days = null;

    public function assets()
    {
        return $this->hasMany(Asset::class)->orderBy('next_service_date');
    }

    public function activeAssets()
    {
        return $this->hasMany(Asset::class)
            ->where('status', 'Actief')
            ->orderBy('next_service_date');
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
}
