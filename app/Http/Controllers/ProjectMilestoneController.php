<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectMilestoneDestroyRequest;
use App\Http\Requests\ProjectMilestoneStoreRequest;
use App\Http\Requests\ProjectMilestoneUpdateRequest;
use App\Models\ProjectMilestone;

class ProjectMilestoneController extends Controller
{
    public function store(ProjectMilestoneStoreRequest $request)
    {
        $milestone = ProjectMilestone::create($request->validated());

        return redirect()
            ->back()
            ->with('success', 'Mijlpaal aangemaakt.')
            ->with('extra', $milestone->load('assignedUser'));
    }

    public function update(ProjectMilestoneUpdateRequest $request, ProjectMilestone $projectmilestone)
    {
        $projectmilestone->update($request->validated());

        return redirect()
            ->back()
            ->with('success', 'Mijlpaal bijgewerkt.');
    }

    public function destroy(ProjectMilestoneDestroyRequest $request, ProjectMilestone $projectmilestone)
    {
        $projectmilestone->delete();

        return redirect()
            ->back()
            ->with('success', 'Mijlpaal verwijderd.');
    }
}
