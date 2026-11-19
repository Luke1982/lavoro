<?php

namespace App\Http\Controllers;

use App\Actions\Projects\AddMilestonesFromTemplate;
use App\Actions\Projects\SaveMilestone;
use App\Http\Requests\ProjectMilestoneDestroyRequest;
use App\Http\Requests\ProjectMilestonesFromTemplateRequest;
use App\Http\Requests\ProjectMilestoneStoreRequest;
use App\Http\Requests\ProjectMilestoneUpdateRequest;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTemplate;

class ProjectMilestoneController extends Controller
{
    public function store(ProjectMilestoneStoreRequest $request, SaveMilestone $save)
    {
        $milestone = $save->create($request->validated());

        return redirect()
            ->back()
            ->with('success', 'Mijlpaal aangemaakt.')
            ->with('extra', $milestone->load('assignedUser'));
    }

    public function update(ProjectMilestoneUpdateRequest $request, ProjectMilestone $projectmilestone, SaveMilestone $save)
    {
        $save->update($projectmilestone, $request->validated());

        return redirect()
            ->back()
            ->with('success', 'Mijlpaal bijgewerkt.');
    }

    public function destroy(ProjectMilestoneDestroyRequest $request, ProjectMilestone $projectmilestone, SaveMilestone $save)
    {
        $save->delete($projectmilestone);

        return redirect()
            ->back()
            ->with('success', 'Mijlpaal verwijderd.');
    }

    public function fromTemplate(
        ProjectMilestonesFromTemplateRequest $request,
        Project $project,
        AddMilestonesFromTemplate $add,
    ) {
        $template = ProjectTemplate::findOrFail($request->validated('project_template_id'));
        $add->execute($project, $template);

        return redirect()
            ->back()
            ->with('success', "Mijlpalen uit sjabloon '" . $template->name . "' toegevoegd.");
    }
}
