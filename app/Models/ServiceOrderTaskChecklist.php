<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * A reusable, ordered set of checks ("Checklist oplevering") attached to task
 * templates as a whole. The checklist decides which of its own checks are
 * required; the link to a task can make the whole list optional there.
 */
class ServiceOrderTaskChecklist extends Model
{
    protected $fillable = ['title'];

    /** Its links to tasks go by foreign key; the checks it holds hang off a morph, so they go here. */
    protected static function booted(): void
    {
        static::deleting(function (self $checklist) {
            $checklist->checkLinks()->delete();
        });
    }

    public function checks(): MorphToMany
    {
        return $this->morphToMany(ServiceOrderTaskCheck::class, 'checkable', 'service_order_task_checkables')
            ->withPivot('id', 'is_required', 'sort_order')
            ->orderByPivot('sort_order');
    }

    public function checkLinks(): MorphMany
    {
        return $this->morphMany(ServiceOrderTaskCheckLink::class, 'checkable')->orderBy('sort_order');
    }
}
