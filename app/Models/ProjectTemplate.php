<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The milestones a kind of job always runs through, so a new project of that kind
 * starts with them instead of with an empty list.
 */
class ProjectTemplate extends Model
{
    protected $fillable = ['name', 'description'];

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectTemplateMilestone::class)->orderBy('position')->orderBy('id');
    }
}
