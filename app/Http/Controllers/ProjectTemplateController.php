<?php

namespace App\Http\Controllers;

use App\Actions\Projects\SaveProjectTemplate;
use App\Http\Requests\ProjectTemplateManageRequest;
use App\Models\ProjectTemplate;

class ProjectTemplateController extends Controller
{
    public function index(ProjectTemplateManageRequest $request)
    {
        return inertia('ProjectTemplates/IndexPage', [
            'templates' => ProjectTemplate::with('milestones')->orderBy('name')->get(),
        ]);
    }

    public function store(ProjectTemplateManageRequest $request, SaveProjectTemplate $save)
    {
        $save->execute(new ProjectTemplate, $request->validated());

        return redirect()->back()->with('success', 'Projectsjabloon aangemaakt.');
    }

    public function update(ProjectTemplateManageRequest $request, ProjectTemplate $projecttemplate, SaveProjectTemplate $save)
    {
        $save->execute($projecttemplate, $request->validated());

        return redirect()->back()->with('success', 'Projectsjabloon bijgewerkt.');
    }

    public function destroy(ProjectTemplateManageRequest $request, ProjectTemplate $projecttemplate)
    {
        $projecttemplate->delete();

        return redirect()->back()->with('success', 'Projectsjabloon verwijderd.');
    }
}
