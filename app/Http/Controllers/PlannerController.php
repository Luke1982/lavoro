<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventReadRequest;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\GeneralSetting;
use App\Models\LocationPing;
use App\Models\Project;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\UserPlanGroup;
use Illuminate\Support\Facades\Auth;

class PlannerController extends Controller
{
    public function index(EventReadRequest $request)
    {
        $user = Auth::user();
        $can_read_all = $user->isAdmin() || $user->hasPermission('serviceorder.read');

        $so_scope = function ($q) use ($user, $can_read_all) {
            if (! $can_read_all) {
                $q->whereHas('executingUsers', fn ($uq) => $uq->where('users.id', $user->id));
            }
        };

        $customer_count = Customer::count();

        $plan_groups = UserPlanGroup::orderBy('sort_order')
            ->orderBy('id')
            ->with('users:id,user_plan_group_id')
            ->get()
            ->map(fn ($g) => [
                'id'         => $g->id,
                'name'       => $g->name,
                'color'      => $g->color,
                'sort_order' => $g->sort_order,
                'user_ids'   => $g->users->pluck('id')->toArray(),
            ]);

        return inertia('Planner/IndexPage', [
            'eventTypes'    => EventType::all(),
            'eventStatusses' => Event::statusses(),
            'noPadding'     => true,
            'allCustomers'  => $customer_count <= 50
                ? Customer::orderBy('name')->get(['id', 'name'])
                : collect(),
            'customersUseAjax' => $customer_count > 50,
            'allServiceOrders' => ServiceOrder::with('customer')->tap($so_scope)->get(),
            'unplannedServiceOrders' => ServiceOrder::with(['customer', 'serviceOrderStage'])
                ->withCount('events')
                ->whereNull('project_id')
                ->whereHas('serviceOrderStage', function ($q) {
                    $q->where('is_plannable_state', true)
                        ->where('is_planned_state', false);
                })
                ->tap($so_scope)
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
                ->select('id', 'name', 'user_plan_group_id')
                ->orderBy('name')
                ->get()
                ->map(fn ($u) => [
                    'id'            => $u->id,
                    'name'          => $u->name,
                    'avatar'        => $u->avatar,
                    'plan_group_id' => $u->user_plan_group_id,
                ]),
            'allPlanUsers' => User::select('id', 'name', 'plannable', 'user_plan_group_id')
                ->orderBy('name')
                ->get()
                ->map(fn ($u) => [
                    'id'            => $u->id,
                    'name'          => $u->name,
                    'avatar'        => $u->avatar,
                    'plannable'     => (bool) $u->plannable,
                    'plan_group_id' => $u->user_plan_group_id,
                ]),
            'planGroups' => $plan_groups,
            'defaultPlannerMinutes' => (int) GeneralSetting::get('defaultplannerminutes', 120),
            'latestPings' => LocationPing::query()
                ->whereIn('id', function ($sub) {
                    $sub->selectRaw('MAX(id)')
                        ->from('location_pings')
                        ->where('recorded_at', '>=', now()->subHours(8))
                        ->groupBy('user_id');
                })
                ->get(['id', 'user_id', 'lat', 'lng'])
                ->keyBy('user_id')
                ->map(fn ($p) => ['lat' => $p->lat, 'lng' => $p->lng]),
        ]);
    }
}
