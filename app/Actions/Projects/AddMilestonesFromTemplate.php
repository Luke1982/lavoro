<?php

namespace App\Actions\Projects;

use App\Domain\Signals\Projects\MilestonesAddedFromTemplate;
use App\Domain\Signals\Signals;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\ProjectTemplateMilestone;

/**
 * Puts a template's milestones on a project, each planned its number of days
 * after the project starts. Without a start date they arrive unplanned.
 */
class AddMilestonesFromTemplate
{
    public function execute(Project $project, ProjectTemplate $template): void
    {
        $steps = $template->milestones()->get();

        if ($steps->isEmpty()) {
            return;
        }

        $steps->each(fn (ProjectTemplateMilestone $step) => $project->milestones()->create([
            'title' => $step->title,
            'description' => $step->description,
            'projected_date' => $step->plannedFor($project->start_date),
        ]));

        Signals::dispatch(new MilestonesAddedFromTemplate($project, $template, $steps->pluck('title')->all()));
    }
}
