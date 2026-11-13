<?php

namespace App\Models;

use App\Models\Traits\OrderedWithinOwner;
use Illuminate\Database\Eloquent\Model;

/**
 * A checklist placed on a task template. Required means the checklist's own
 * required checks stay required; optional makes every check in it optional for
 * that task.
 */
class ServiceOrderTaskChecklistLink extends Model
{
    use OrderedWithinOwner;

    public const OWNER = 'checklistable';

    protected $table = 'service_order_task_checklistables';

    protected $fillable = [
        'service_order_task_checklist_id',
        'checklistable_type',
        'checklistable_id',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];
}
