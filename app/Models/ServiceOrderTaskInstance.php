<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceOrderTaskInstance extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_order_id',
        'service_order_task_id',
        'product_id',
        'quantity',
        'title',
        'description',
        'is_complete',
    ];

    protected $casts = [
        'is_complete' => 'boolean',
        'quantity'    => 'integer',
    ];

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function serviceOrderTask()
    {
        return $this->belongsTo(ServiceOrderTask::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function getEffectiveDescriptionAttribute(): string
    {
        return $this->description ?? $this->serviceOrderTask?->description ?? '';
    }
}
