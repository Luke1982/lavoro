<?php

namespace App\Http\Controllers;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\UpdateProject;
use App\Domain\Planning\Clock;
use App\Domain\Search\SearchTerm;
use App\Enums\ProjectStatuses;
use App\Http\Requests\ProjectDestroyRequest;
use App\Http\Requests\ProjectReadRequest;
use App\Http\Requests\ProjectStoreRequest;
use App\Http\Requests\ProjectTimelineRequest;
use App\Http\Requests\ProjectUpdateRequest;
use App\Models\Customer;
use App\Models\DocumentCategory;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\ServiceOrderStage;
use App\Models\User;
use App\Services\ProjectFigures;
use Illuminate\Database\Eloquent\Builder;

class ProjectController extends Controller
{
    public function index(ProjectReadRequest $request)
    {
        $list = $request->listQuery();

        $projects = Project::query()
            ->with(['customer:id,name', 'projectManager:id,name'])
            ->withCount([
                'serviceOrders',
                'serviceOrders as closed_service_orders_count' => fn (Builder $query) => $query
                    ->whereHas('serviceOrderStage', fn (Builder $stage) => $stage->closesOrder()),
                'milestones',
                'milestones as reached_milestones_count' => fn (Builder $query) => $query->whereNotNull('actual_date'),
                'milestones as overdue_milestones_count' => fn (Builder $query) => $query
                    ->whereNull('actual_date')
                    ->whereDate('projected_date', '<', Clock::today()),
            ])
            ->tap(fn (Builder $query) => $this->filter($query, $list))
            ->tap(fn (Builder $query) => $this->sort($query, $list['sort'], $list['direction']))
            ->paginate(20)
            ->withQueryString();

        return inertia('Projects/IndexPage', [
            'projects' => $projects,
            ...$this->customerPicker($list['customer']),
            'users' => User::canLeadProjects()->orderBy('name')->get(['id', 'name']),
            'statuses' => ProjectStatuses::comboBoxArray(),
            'templates' => ProjectTemplate::orderBy('name')->get(['id', 'name']),
            'list' => $list,
        ]);
    }

    public function show(ProjectReadRequest $request, Project $project, ProjectFigures $figures)
    {
        $project->load([
            'customer',
            'projectManager',
            'contact',
            'linkedLocation',
            'milestones.assignedUser',
            'serviceOrders.customer:id,name,city',
            ...Project::relationsForAddress('serviceOrders.project'),
            'serviceOrders.executingUsers:id,name',
            'serviceOrders.taskInstances:id,service_order_id,is_complete,is_cancelled',
            'serviceOrders.events.executingUsers:id,name',
            'documents.category',
            'documents.user:id,name',
            'images' => fn ($query) => $query->withExists('shares as is_shared'),
            'activities' => fn ($query) => $query->visibleTo($request->user())
                ->with(['user:id,name', 'fieldChanges'])
                ->orderByDesc('activityables.created_at'),
        ]);

        if ($request->user()->can('manageFinancials', $project)) {
            $project->load('financialNotesUpdatedBy:id,name')->makeVisible(Project::FINANCIAL_ATTRIBUTES);
        }

        return inertia('Projects/ShowPage', [
            'project' => $project,
            'progress' => $figures->summary($project, $request->user()),
            'contacts' => $project->customer?->contacts()->orderBy('first_name')->get()
                ->map(fn ($contact) => ['id' => $contact->id, 'name' => $contact->full_name]) ?? [],
            'templates' => ProjectTemplate::orderBy('name')->get(['id', 'name']),
            'documentCategories' => DocumentCategory::forPicker(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'users' => User::canLeadProjects()->orderBy('name')->get(['id', 'name']),
            'statuses' => ProjectStatuses::comboBoxArray(),
            'serviceOrderStages' => ServiceOrderStage::orderBy('order')->get(),
        ]);
    }

    public function store(ProjectStoreRequest $request, CreateProject $create)
    {
        $project = $create->execute($request->validated());

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Project ' . $project->number . ' aangemaakt.');
    }

    public function update(ProjectUpdateRequest $request, Project $project, UpdateProject $update)
    {
        $warning = $update->execute($project, $request->validated());

        return $warning
            ? redirect()->back()->with('warning', $warning)
            : redirect()->back()->with('success', 'Project bijgewerkt.');
    }

    public function destroy(ProjectDestroyRequest $request, Project $project, DeleteProject $delete)
    {
        $delete->execute($project);

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

    /** @param array{search: string, status: string, manager: ?int, customer: ?int} $list */
    private function filter(Builder $query, array $list): void
    {
        match ($list['status']) {
            ProjectReadRequest::RUNNING => $query->active(),
            ProjectReadRequest::EVERY_STATUS => null,
            default => $query->where('status', $list['status']),
        };

        $query
            ->when($list['search'] !== '', fn (Builder $q) => $q->matchesSearch(SearchTerm::like($list['search'])))
            ->when($list['manager'], fn (Builder $q, int $id) => $q->where('project_manager_id', $id))
            ->when($list['customer'], fn (Builder $q, int $id) => $q->where('customer_id', $id));
    }

    private function sort(Builder $query, ?string $column, string $direction): void
    {
        match ($column) {
            null => $query->inNumberOrder(),
            'number' => $query->inNumberOrder($direction),
            'customer' => $query->orderBy(
                Customer::select('name')->whereColumn('customers.id', 'projects.customer_id'),
                $direction,
            ),
            default => $query->orderBy($column, $direction),
        };

        $query->orderByDesc('id');
    }

    /**
     * Past the threshold the customer pickers search instead of holding every
     * customer; only the one being filtered on still travels along, or the filter
     * would show a blank where its name goes.
     *
     * @return array{customers: array<int, array<string, mixed>>, customersUseAjax: bool}
     */
    private function customerPicker(?int $filtered_customer_id): array
    {
        $use_ajax = Customer::count() > Customer::COMBO_AJAX_THRESHOLD;

        return [
            'customers' => Customer::query()
                ->when($use_ajax, fn (Builder $query) => $query->whereKey($filtered_customer_id))
                ->orderBy('name')
                ->get(['id', 'name', 'city'])
                ->map->toComboOption()
                ->all(),
            'customersUseAjax' => $use_ajax,
        ];
    }
}
