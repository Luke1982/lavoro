<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Customer;
use App\Http\Requests\EventReadRequest;
use App\Models\EventType;
use App\Models\Project;
use App\Models\ServiceOrder;
use App\Models\User;

class PlannerController extends Controller
{
    public function index(EventReadRequest $request)
    {
        return inertia('Planner/IndexPage', [
            'eventTypes' => EventType::all(),
            'eventStatusses' => Event::statusses(),
            'noPadding' => true,
            'allCustomers' => Customer::all(),
            'allServiceOrders' => ServiceOrder::with('customer')->get(),
            'unplannedServiceOrders' => ServiceOrder::with(['customer', 'serviceOrderStage'])
                ->withCount('events')
                ->whereNull('project_id')
                ->whereHas('serviceOrderStage', function ($q) {
                    $q->where('is_plannable_state', true)
                        ->where('is_planned_state', false);
                })
                ->orderByDesc('created_at')
                ->get(),
            'projects' => Project::query()
                ->whereNotNull('start_date')
                ->whereNotNull('end_date')
                ->with([
                    'customer:id,name',
                    'serviceOrders' => fn ($q) => $q->doesntHave('events')->orderBy('id'),
                ])
                ->orderBy('start_date')
                ->get(),
            'allUsers' => User::select('id', 'name')->get(),
            'plannableUsers' => User::where('plannable', true)
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'avatar' => $u->avatar,
                ]),
        ]);
    }
}
