<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderPlanGroupsRequest;
use App\Http\Requests\StorePlanGroupRequest;
use App\Http\Requests\UpdatePlanGroupRequest;
use App\Models\User;
use App\Models\UserPlanGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserPlanGroupController extends Controller
{
    private function authorizeManage(): void
    {
        $user = Auth::user();
        abort_unless(
            $user && ($user->isAdmin()
                || $user->hasPermission('event.see_all')
                || $user->hasPermission('event.create_others')),
            403
        );
    }

    public function index()
    {
        $this->authorizeManage();

        $groups = UserPlanGroup::orderBy('sort_order')->orderBy('id')
            ->with('users:id')
            ->get();

        return response()->json($groups->map(fn ($g) => [
            'id'         => $g->id,
            'name'       => $g->name,
            'color'      => $g->color,
            'sort_order' => $g->sort_order,
            'user_ids'   => $g->users->pluck('id')->toArray(),
        ]));
    }

    public function store(StorePlanGroupRequest $request)
    {
        $max = UserPlanGroup::max('sort_order') ?? -1;

        $group = UserPlanGroup::create([
            ...$request->validated(),
            'sort_order' => $max + 1,
        ]);

        return response()->json([
            'id'         => $group->id,
            'name'       => $group->name,
            'color'      => $group->color,
            'sort_order' => $group->sort_order,
            'user_ids'   => [],
        ], 201);
    }

    public function update(UpdatePlanGroupRequest $request, UserPlanGroup $group)
    {
        $group->update($request->validated());

        return response()->json([
            'id'         => $group->id,
            'name'       => $group->name,
            'color'      => $group->color,
            'sort_order' => $group->sort_order,
        ]);
    }

    public function destroy(UserPlanGroup $group)
    {
        $group->delete();

        return response()->noContent();
    }

    public function reorder(ReorderPlanGroupsRequest $request)
    {
        foreach ($request->validated()['ids'] as $position => $id) {
            UserPlanGroup::where('id', $id)->update(['sort_order' => $position]);
        }

        return response()->noContent();
    }

    public function syncUserGroups(Request $request, User $user)
    {
        $this->authorizeManage();

        $data = $request->validate([
            'group_ids'   => ['array'],
            'group_ids.*' => ['integer', 'exists:user_plan_groups,id'],
        ]);

        $user->planGroups()->sync($data['group_ids'] ?? []);

        return response()->noContent();
    }
}
