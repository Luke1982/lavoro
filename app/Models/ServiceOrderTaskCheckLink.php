<?php

namespace App\Models;

use App\Models\Traits\OrderedWithinOwner;
use Illuminate\Database\Eloquent\Model;

/**
 * A check placed on a task template or inside a checklist, with whether it is
 * required there and where it stands in the order.
 */
class ServiceOrderTaskCheckLink extends Model
{
    use OrderedWithinOwner;

    public const OWNER = 'checkable';

    protected $table = 'service_order_task_checkables';

    protected $fillable = [
        'service_order_task_check_id',
        'checkable_type',
        'checkable_id',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];
}
