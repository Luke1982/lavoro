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
        'completed_at',
        'completed_by',
        'signed_by',
        'signature_base64',
        'signed_at',
        'is_cancelled',
        'cancellation_reason',
    ];

    protected $casts = [
        'is_complete' => 'boolean',
        'quantity' => 'integer',
        'completed_at' => 'datetime',
        'signed_at' => 'datetime',
        'is_cancelled' => 'boolean',
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

    public function userRoles()
    {
        return $this->morphToMany(UserRole::class, 'userroleable')->withTimestamps();
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by')->withTrashed();
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
