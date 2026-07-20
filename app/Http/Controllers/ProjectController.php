<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatuses;
use App\Http\Requests\ProjectDestroyRequest;
use App\Http\Requests\ProjectReadRequest;
use App\Http\Requests\ProjectStoreRequest;
use App\Http\Requests\ProjectTimelineRequest;
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
            'projects' => $query->orderBy('created_at', 'desc')->paginate(20)->appends(['search' => $search]),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'users' => User::canLeadProjects()->orderBy('name')->get(['id', 'name']),
            'statuses' => ProjectStatuses::comboBoxArray(),
            'search' => $search,
        ]);
    }

    public function show(ProjectReadRequest $request, Project $project)
    {
        $project->load([
            'customer',
            'projectManager',
            'milestones.assignedUser',
            'serviceOrders.serviceJobs',
            'documents',
            'images',
        ]);

        if ($request->user()->can('manageFinancials', $project)) {
            $project->load('financialNotesUpdatedBy:id,name');
        } else {
            $project->makeHidden([
                'financial_notes',
                'financial_notes_updated_at',
                'financial_notes_updated_by',
            ]);
        }

        return inertia('Projects/ShowPage', [
            'project' => $project,
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'users' => User::canLeadProjects()->orderBy('name')->get(['id', 'name']),
            'statuses' => ProjectStatuses::comboBoxArray(),
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

    public function timeline(ProjectTimelineRequest $request, Project $project)
    {
        $project->load([
            'milestones.assignedUser',
            'serviceOrders.serviceOrderStage',
            'serviceOrders.executingUsers',
            'serviceOrders.taskInstances.completedBy',
            'serviceOrders.events.executingUsers',
            'serviceOrders.events.eventType',
            'serviceOrders.tickets',
        ]);

        return response()->json([
            'milestones' => $project->milestones->map(fn ($ms) => [
                'id' => $ms->id,
                'title' => $ms->title,
                'projected_date' => $ms->projected_date,
                'actual_date' => $ms->actual_date,
                'assigned_user' => $ms->assignedUser ? [
                    'id' => $ms->assignedUser->id,
                    'name' => $ms->assignedUser->name,
                ] : null,
            ]),
            'service_orders' => $project->serviceOrders->map(fn ($so) => [
                'id' => $so->id,
                'description' => $so->description,
                'is_closed' => $so->is_closed,
                'stage' => $so->serviceOrderStage ? [
                    'name' => $so->serviceOrderStage->name,
                    'is_closed_state' => $so->serviceOrderStage->is_closed_state,
                ] : null,
                'actual_start_time' => $so->actual_start_time,
                'actual_end_time' => $so->actual_end_time,
                'created_at' => $so->created_at,
                'executing_users' => $so->executingUsers->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                ]),
                'events' => $so->events->map(fn ($e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'start' => $e->start,
                    'end' => $e->end,
                    'color' => $e->eventType?->color,
                    'executing_users' => $e->executingUsers->map(fn ($u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                    ]),
                ]),
                'task_instances' => $so->taskInstances->map(fn ($ti) => [
                    'id' => $ti->id,
                    'title' => $ti->title ?? $ti->serviceOrderTask?->title,
                    'is_complete' => $ti->is_complete,
                    'is_cancelled' => $ti->is_cancelled,
                    'completed_at' => $ti->completed_at,
                    'completed_by' => $ti->completedBy ? [
                        'id' => $ti->completedBy->id,
                        'name' => $ti->completedBy->name,
                    ] : null,
                ]),
                'tickets' => $so->tickets->map(fn ($t) => [
                    'id' => $t->id,
                    'subject' => $t->subject,
                    'status' => $t->status,
                    'priority' => $t->priority,
                    'created_at' => $t->created_at,
                    'closed_on' => $t->closed_on,
                ]),
            ]),
        ]);
    }
}
