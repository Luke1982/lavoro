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
        'signed_by',
        'signature_base64',
        'signed_at',
    ];

    protected $casts = [
        'is_complete' => 'boolean',
        'quantity'    => 'integer',
        'signed_at'   => 'datetime',
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
