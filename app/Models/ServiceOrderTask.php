<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class ServiceOrderTask extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description'];

    /** The checks and checklists placed on the template hang off morphs, so they go here. */
    protected static function booted(): void
    {
        static::deleting(function (self $task) {
            $task->checkLinks()->delete();
            $task->checklistLinks()->delete();
        });
    }

    public function instances()
    {
        return $this->hasMany(ServiceOrderTaskInstance::class);
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

    public function checklists(): MorphToMany
    {
        return $this->morphToMany(
            ServiceOrderTaskChecklist::class,
            'checklistable',
            'service_order_task_checklistables'
        )
            ->withPivot('id', 'is_required', 'sort_order')
            ->orderByPivot('sort_order');
    }

    public function checklistLinks(): MorphMany
    {
        return $this->morphMany(ServiceOrderTaskChecklistLink::class, 'checklistable')->orderBy('sort_order');
    }
}
