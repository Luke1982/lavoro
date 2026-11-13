<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A check as it stands on one werkbon task: a copy of the title, the checklist
 * it came from and whether it is required, made when the task was created, so
 * renaming or deleting the template never rewrites what was done.
 *
 * `unit_index` is the sold unit it belongs to when the task checks per machine,
 * null when the checks are done once for the whole task. Once that unit's
 * machine is registered, `asset_id` ties the check to it.
 */
class ServiceOrderTaskInstanceCheck extends Model
{
    protected $fillable = [
        'service_order_task_instance_id',
        'service_order_task_check_id',
        'service_order_task_checklist_id',
        'title',
        'checklist_title',
        'is_required',
        'is_extra',
        'is_done',
        'unit_index',
        'asset_id',
        'sort_order',
        'checked_at',
        'checked_by',
    ];

    protected $casts = [
        'service_order_task_instance_id' => 'integer',
        'service_order_task_check_id' => 'integer',
        'service_order_task_checklist_id' => 'integer',
        'asset_id' => 'integer',
        'is_required' => 'boolean',
        'is_extra' => 'boolean',
        'is_done' => 'boolean',
        'unit_index' => 'integer',
        'sort_order' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ServiceOrderTaskInstance::class, 'service_order_task_instance_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by')->withTrashed();
    }
}
