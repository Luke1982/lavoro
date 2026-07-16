<?php

namespace App\Models;

use App\Services\TaskInstanceSerialSlotService;
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

    /**
     * The machines this task is expected to deliver and the ones registered so far.
     * Not appended by default: it walks the product's bundle tree, so only the pages
     * that show the serial drawer ask for it.
     *
     * @return array<int, array{product_id: int, label: string, expected: int,
     *                          assets: array<int, array{id: int, serial_number: ?string}>}>
     */
    public function getSerialSlotsAttribute(): array
    {
        return app(TaskInstanceSerialSlotService::class)->groups($this);
    }
}
