<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ProjectTemplateMilestone extends Model
{
    protected $fillable = ['project_template_id', 'title', 'description', 'offset_days', 'position'];

    protected function casts(): array
    {
        return [
            'offset_days' => 'integer',
            'position' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProjectTemplate::class, 'project_template_id');
    }

    /** Where this step lands for a project starting on the given day, or nowhere without a start. */
    public function plannedFor(?string $project_start): ?string
    {
        return $project_start
            ? Carbon::parse($project_start)->addDays($this->offset_days)->toDateString()
            : null;
    }
}
