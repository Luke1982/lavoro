<?php

namespace App\Models;

use App\Enums\AssetStatusses;
use App\Enums\EventStatusses;
use App\Enums\ServiceJobOutcomes;
use App\Models\Traits\HasCustomFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Asset extends Model
{
    /** @use HasFactory<\Database\Factories\AssetsFactory> */
    use HasFactory;
    use HasCustomFields;

    protected $fillable = [
        'product_id',
        'customer_id',
        'serial_number',
        'next_service_date',
        'status',
    ];

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

    public function customer()
    {
        return $this->belongsTo(Customer::class);
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
}
