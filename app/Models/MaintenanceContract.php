<?php

namespace App\Models;

use App\Enums\ContractInterval;
use App\Models\Traits\HasActivities;
use App\Models\Traits\RemarkableTrait;
use Carbon\Carbon;
use Database\Factories\MaintenanceContractFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class MaintenanceContract extends Model
{
    use HasActivities;

    /** @use HasFactory<MaintenanceContractFactory> */
    use HasFactory;

    use RemarkableTrait;

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

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'price' => 'decimal:2',
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
        static::deleting(function (MaintenanceContract $contract) {
            $id = $contract->id;
            $morph_class = MaintenanceContract::class;
            $pivot_tables = [
                'assetables' => 'assetable',
                'activityables' => 'activityable',
                'remarkables' => 'remarkable',
            ];

            foreach ($pivot_tables as $table => $morph) {
                DB::table($table)
                    ->where("{$morph}_type", $morph_class)
                    ->where("{$morph}_id", $id)
                    ->delete();
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function assets()
    {
        return $this->morphToMany(Asset::class, 'assetable')
            ->withPivot(['id', 'frequency', 'frequency_days', 'last_generated_at'])
            ->withTimestamps();
    }

    public function getLocationsAttribute()
    {
        return $this->assets->map->location->filter()->unique('id')->values();
    }

    public function generatedServiceOrders()
    {
        return $this->hasMany(ServiceOrder::class)->orderByDesc('created_at');
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
