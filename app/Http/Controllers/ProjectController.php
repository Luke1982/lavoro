<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatuses;
use App\Http\Requests\ProjectDestroyRequest;
use App\Http\Requests\ProjectReadRequest;
use App\Http\Requests\ProjectStoreRequest;
use App\Http\Requests\ProjectUpdateRequest;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;

class ProjectController extends Controller
{
    public function index(ProjectReadRequest $request)
    {
        $search = trim((string) $request->input('search', ''));

        $query = Project::with(['customer', 'projectManager']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('projectManager', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        return inertia('Projects/IndexPage', [
            'projects'   => $query->orderBy('created_at', 'desc')->paginate(20)->appends(['search' => $search]),
            'customers'  => Customer::orderBy('name')->get(['id', 'name']),
            'users'      => User::canLeadProjects()->orderBy('name')->get(['id', 'name']),
            'statuses'   => ProjectStatuses::comboBoxArray(),
            'search'     => $search,
        ]);
    }

    public function show(ProjectReadRequest $request, Project $project)
    {
        return inertia('Projects/ShowPage', [
            'project'   => $project->load([
                'customer',
                'projectManager',
                'milestones.assignedUser',
                'serviceOrders.serviceJobs',
                'documents',
            ]),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'users'     => User::canLeadProjects()->orderBy('name')->get(['id', 'name']),
            'statuses'  => ProjectStatuses::comboBoxArray(),
        ]);
    }

    public function store(ProjectStoreRequest $request)
    {
        $project = Project::create($request->validated());

        return redirect()
            ->route('projects.index')
            ->with('success', 'Project aangemaakt.')
            ->with('extra', $project->load(['customer', 'projectManager']));
    }

    public function update(ProjectUpdateRequest $request, Project $project)
    {
        $project->update($request->validated());

        return redirect()->back()->with('success', 'Project bijgewerkt.');
    }

    public function destroy(ProjectDestroyRequest $request, Project $project)
    {
        $project->delete();

        return redirect()
            ->route('projects.index')
            ->with('success', 'Project verwijderd.');
    }
}
