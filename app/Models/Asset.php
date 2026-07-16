<?php

namespace App\Models;

use App\Enums\AssetStatusses;
use App\Enums\EventStatusses;
use App\Enums\ServiceJobOutcomes;
use App\Models\Traits\HasCustomFields;
use Database\Factories\AssetsFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasCustomFields;

    /** @use HasFactory<AssetsFactory> */
    use HasFactory;

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
        static::saving(function (Asset $asset) {
            if (($asset->customer_id === null) === ($asset->parent_asset_id === null)) {
                throw new DomainException(
                    'Een machine hoort óf bij een klant óf bij een bovenliggende machine, niet bij beide.'
                );
            }
        });
    }

    public function scopeUpcomingAndUnplanned($query, int $days = 60)
    {
        return $query->whereBetween('next_service_date', [now(), now()->addDays($days)])
            ->where('status', '!=', AssetStatusses::inactive->value)
            ->whereDoesntHave('servicejobs', function ($query) {
                $query->whereNull('completed_on')
                    ->whereHas('serviceOrder', function ($query) {
                        $query->where('status', '!=', 'closed')
                            ->whereHas('events', function ($query) {
                                $query->where('status', '!=', EventStatusses::completed->value)
                                    ->where('start', '>', now());
                            });
                    });
            });
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
     */
    public function rootAsset(): Asset
    {
        $node = $this;

        while ($node->parent_asset_id !== null) {
            $node = $node->parentAsset()->firstOrFail();
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

    public function maintenanceContracts()
    {
        return $this->morphedByMany(MaintenanceContract::class, 'assetable')
            ->withPivot(['id', 'frequency', 'frequency_days', 'last_generated_at'])
            ->withTimestamps();
    }
}
