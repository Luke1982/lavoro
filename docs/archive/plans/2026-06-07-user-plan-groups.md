# UserPlanGroup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `UserPlanGroup` model so plannable users can be grouped, ordered, and displayed with colored group bars in the planner resource panel.

**Architecture:** Nullable FK `user_plan_group_id` on `users` → `user_plan_groups`. A new `PlanGroupsWidget.vue` in the right sidebar (visible to `event.see_all` / `event.create_others`) manages groups and user assignment via emit → parent axios. `ResourcePlannerWidget.vue` sorts users by group order then alpha, and overlays colored vertical bars on the left sidebar.

**Tech Stack:** Laravel 12, Inertia, Vue 3 Composition API, Tailwind CSS, Heroicons, HTML5 drag-and-drop API.

---

### Task 1: Migration — create user_plan_groups table

**Files:**
- Create: `database/migrations/2026_06_07_100001_create_user_plan_groups_table.php`

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_plan_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color')->default('#2563ff');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_plan_groups');
    }
};
```

- [ ] **Step 2: Run and verify**

```bash
php artisan migrate
```

Expected: `user_plan_groups` table created, no errors.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_07_100001_create_user_plan_groups_table.php
git commit -m "feat(plan-groups): create user_plan_groups table"
```

---

### Task 2: Migration — add FK to users

**Files:**
- Create: `database/migrations/2026_06_07_100002_add_user_plan_group_id_to_users_table.php`

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('user_plan_group_id')
                ->nullable()
                ->after('plannable')
                ->constrained('user_plan_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\UserPlanGroup::class);
            $table->dropColumn('user_plan_group_id');
        });
    }
};
```

- [ ] **Step 2: Run and verify**

```bash
php artisan migrate
```

Expected: FK column added to `users`, no errors.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_07_100002_add_user_plan_group_id_to_users_table.php
git commit -m "feat(plan-groups): add user_plan_group_id FK to users"
```

---

### Task 3: Models

**Files:**
- Create: `app/Models/UserPlanGroup.php`
- Modify: `app/Models/User.php`

- [ ] **Step 1: Create UserPlanGroup model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPlanGroup extends Model
{
    protected $fillable = ['name', 'color', 'sort_order'];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
```

- [ ] **Step 2: Update User model — add to fillable and casts, add belongsTo**

In `app/Models/User.php`, update `$fillable`:

```php
protected $fillable = [
    'name',
    'email',
    'password',
    'plannable',
    'user_plan_group_id',
];
```

In the `casts()` method, add:

```php
'user_plan_group_id' => 'integer',
```

Add the relationship method after `roles()`:

```php
public function planGroup()
{
    return $this->belongsTo(UserPlanGroup::class, 'user_plan_group_id');
}
```

- [ ] **Step 3: Verify — boot the app**

```bash
php artisan tinker --execute="echo App\Models\UserPlanGroup::count();"
```

Expected: `0` (no error).

- [ ] **Step 4: Commit**

```bash
git add app/Models/UserPlanGroup.php app/Models/User.php
git commit -m "feat(plan-groups): add UserPlanGroup model and User relationship"
```

---

### Task 4: Form Requests

**Files:**
- Create: `app/Http/Requests/StorePlanGroupRequest.php`
- Create: `app/Http/Requests/UpdatePlanGroupRequest.php`
- Create: `app/Http/Requests/ReorderPlanGroupsRequest.php`
- Create: `app/Http/Requests/UpdateUserPlannableRequest.php`

- [ ] **Step 1: StorePlanGroupRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StorePlanGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin()
            || $user->hasPermission('event.see_all')
            || $user->hasPermission('event.create_others'));
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
```

- [ ] **Step 2: UpdatePlanGroupRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdatePlanGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin()
            || $user->hasPermission('event.see_all')
            || $user->hasPermission('event.create_others'));
    }

    public function rules(): array
    {
        return [
            'name'  => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
```

- [ ] **Step 3: ReorderPlanGroupsRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ReorderPlanGroupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin()
            || $user->hasPermission('event.see_all')
            || $user->hasPermission('event.create_others'));
    }

    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array'],
            'ids.*' => ['integer', 'exists:user_plan_groups,id'],
        ];
    }
}
```

- [ ] **Step 4: UpdateUserPlannableRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateUserPlannableRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin()
            || $user->hasPermission('event.see_all')
            || $user->hasPermission('event.create_others'));
    }

    public function rules(): array
    {
        return [
            'plannable' => ['required', 'boolean'],
        ];
    }
}
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/StorePlanGroupRequest.php \
        app/Http/Requests/UpdatePlanGroupRequest.php \
        app/Http/Requests/ReorderPlanGroupsRequest.php \
        app/Http/Requests/UpdateUserPlannableRequest.php
git commit -m "feat(plan-groups): add Form Requests for plan group API"
```

---

### Task 5: UserPlanGroupController + UserPlannableController

**Files:**
- Create: `app/Http/Controllers/UserPlanGroupController.php`
- Create: `app/Http/Controllers/UserPlannableController.php`

- [ ] **Step 1: Create UserPlanGroupController**

```php
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
            ->with('users:id,user_plan_group_id')
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

    public function destroy(UpdatePlanGroupRequest $request, UserPlanGroup $group)
    {
        User::where('user_plan_group_id', $group->id)
            ->update(['user_plan_group_id' => null]);

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

    public function assignUser(Request $request, UserPlanGroup $group, User $user)
    {
        $this->authorizeManage();
        $user->update(['user_plan_group_id' => $group->id]);
        return response()->noContent();
    }

    public function removeUser(Request $request, UserPlanGroup $group, User $user)
    {
        $this->authorizeManage();
        if ($user->user_plan_group_id === $group->id) {
            $user->update(['user_plan_group_id' => null]);
        }
        return response()->noContent();
    }
}
```

- [ ] **Step 2: Create UserPlannableController**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserPlannableRequest;
use App\Models\User;

class UserPlannableController extends Controller
{
    public function __invoke(UpdateUserPlannableRequest $request, User $user)
    {
        $user->update(['plannable' => $request->validated()['plannable']]);
        return response()->noContent();
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/UserPlanGroupController.php \
        app/Http/Controllers/UserPlannableController.php
git commit -m "feat(plan-groups): add UserPlanGroupController and UserPlannableController"
```

---

### Task 6: API Routes

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Add routes — IMPORTANT: reorder route must come before {group} wildcard**

Replace the content of `routes/api.php`:

```php
<?php

use App\Http\Controllers\Api\GoogleIntegrationStatusController;
use App\Http\Controllers\EventApiController;
use App\Http\Controllers\GeneralSettingController;
use App\Http\Controllers\ProjectApiController;
use App\Http\Controllers\UnavailabilityApiController;
use App\Http\Controllers\UserPlanGroupController;
use App\Http\Controllers\UserPlannableController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'auth:sanctum'], function () {
    Route::resource('events', EventApiController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('events/{event}/send-confirmation', [EventApiController::class, 'sendConfirmation']);

    Route::get('projects', [ProjectApiController::class, 'index']);
    Route::get('projectmilestones', [ProjectApiController::class, 'milestones']);

    Route::get('google/integration/status', GoogleIntegrationStatusController::class)
        ->name('api.google.integration.status');

    Route::get('unavailabilities', [UnavailabilityApiController::class, 'index']);

    Route::put('settings/{key}', [GeneralSettingController::class, 'update']);

    // Plan groups — reorder MUST be registered before {group} to avoid wildcard capture
    Route::get('plan-groups', [UserPlanGroupController::class, 'index']);
    Route::post('plan-groups', [UserPlanGroupController::class, 'store']);
    Route::put('plan-groups/reorder', [UserPlanGroupController::class, 'reorder']);
    Route::put('plan-groups/{group}', [UserPlanGroupController::class, 'update']);
    Route::delete('plan-groups/{group}', [UserPlanGroupController::class, 'destroy']);
    Route::put('plan-groups/{group}/users/{user}', [UserPlanGroupController::class, 'assignUser']);
    Route::delete('plan-groups/{group}/users/{user}', [UserPlanGroupController::class, 'removeUser']);

    Route::patch('users/{user}/plannable', UserPlannableController::class);
});
```

- [ ] **Step 2: Verify routes registered**

```bash
php artisan route:list --path=api/plan-groups
```

Expected: 7 rows for plan-groups routes, with `reorder` appearing before `{group}` in the list.

- [ ] **Step 3: Commit**

```bash
git add routes/api.php
git commit -m "feat(plan-groups): register plan-group and plannable API routes"
```

---

### Task 7: PlannerController updates

**Files:**
- Modify: `app/Http/Controllers/PlannerController.php`

- [ ] **Step 1: Update the controller to pass plan_groups, update plannableUsers, add allPlanUsers**

Replace the full file:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventReadRequest;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
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
        ]);
    }
}
```

- [ ] **Step 2: Visit planner in browser and confirm no 500 errors**

```bash
php artisan serve
# open http://localhost:8000/planner
```

Expected: page loads, no PHP errors.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/PlannerController.php
git commit -m "feat(plan-groups): pass plan_groups, allPlanUsers, plan_group_id to planner page"
```

---

### Task 8: PlanGroupsWidget.vue

**Files:**
- Create: `resources/js/Components/Planner/PlanGroupsWidget.vue`

- [ ] **Step 1: Create the component**

```vue
<template>
    <BoxComponent extra-classes="flex flex-col" padding="flex-1 min-h-0 overflow-y-auto">
        <div class="flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between px-3 pt-2 pb-2">
                <span class="text-xs text-lavoro-dark font-medium">Groepen</span>
                <button
                    class="flex items-center gap-1 text-xs text-lavoro-blue hover:underline"
                    @click="startNewGroup">
                    <PlusIcon class="size-3" />
                    Toevoegen
                </button>
            </div>

            <!-- New group inline form -->
            <div v-if="newGroup !== null"
                class="flex items-center gap-2 px-3 pb-2 border-b border-b-lavoro-gray-150">
                <input
                    ref="newNameInput"
                    v-model="newGroup.name"
                    type="text"
                    placeholder="Groepsnaam"
                    class="flex-1 text-xs border border-gray-300 dark:border-slate-700 rounded px-2 py-1 dark:bg-slate-800 focus:outline-none focus:border-lavoro-blue"
                    @keydown.enter="confirmNewGroup"
                    @keydown.escape="newGroup = null" />
                <input
                    type="color"
                    v-model="newGroup.color"
                    class="h-6 w-6 cursor-pointer rounded border-0 p-0 bg-transparent" />
                <button
                    class="text-xs font-semibold text-lavoro-blue"
                    @click="confirmNewGroup">OK</button>
                <button
                    class="text-xs text-gray-400 hover:text-gray-600"
                    @click="newGroup = null">✕</button>
            </div>

            <!-- Groups -->
            <div
                v-for="group in planGroups"
                :key="group.id"
                class="border-b border-b-lavoro-gray-150"
                @dragover.prevent="onDragOver($event, group.id)"
                @dragleave.self="dropTargetId = undefined"
                @drop.prevent="onDrop($event, group.id)">

                <!-- Group header row -->
                <div
                    class="flex items-center gap-2 px-3 py-2 select-none transition-colors"
                    :class="dropTargetId === group.id && activeType === 'user' ? 'bg-lavoro-lightblue' : ''"
                    draggable="true"
                    @dragstart="onGroupDragStart($event, group)"
                    @dragend="resetDrag">

                    <Bars3Icon class="size-3.5 text-gray-400 cursor-grab shrink-0" />

                    <!-- Color swatch / picker -->
                    <label class="cursor-pointer shrink-0 relative">
                        <span
                            class="block h-3.5 w-3.5 rounded-sm ring-1 ring-black/10"
                            :style="{ background: group.color }" />
                        <input
                            type="color"
                            :value="group.color"
                            class="absolute inset-0 opacity-0 w-full h-full cursor-pointer"
                            @change="e => $emit('group-updated', group.id, { color: e.target.value })" />
                    </label>

                    <!-- Editable name -->
                    <input
                        v-if="editingGroupId === group.id"
                        :ref="el => { if (el) editNameInputEl = el }"
                        v-model="editingName"
                        type="text"
                        class="flex-1 text-xs border border-lavoro-blue rounded px-1 py-0.5 dark:bg-slate-800 focus:outline-none"
                        @blur="confirmEditName(group)"
                        @keydown.enter="confirmEditName(group)"
                        @keydown.escape="editingGroupId = null" />
                    <span
                        v-else
                        class="flex-1 text-xs font-medium text-lavoro-dark truncate cursor-pointer hover:text-lavoro-blue"
                        @click="startEditName(group)">{{ group.name }}</span>

                    <span class="text-[10px] text-gray-400 shrink-0">{{ usersInGroup(group.id).length }}</span>

                    <button
                        class="text-gray-400 hover:text-red-500 shrink-0"
                        @click="deleteGroup(group)">
                        <TrashIcon class="size-3.5" />
                    </button>

                    <button
                        class="text-gray-400 shrink-0"
                        @click="toggleCollapse(group.id)">
                        <ChevronDownIcon v-if="!collapsedGroups.has(group.id)" class="size-3.5" />
                        <ChevronRightIcon v-else class="size-3.5" />
                    </button>
                </div>

                <!-- Users in group -->
                <div v-if="!collapsedGroups.has(group.id)">
                    <div
                        v-for="user in usersInGroup(group.id)"
                        :key="user.id"
                        class="flex items-center gap-2 px-3 py-1.5 border-t border-t-lavoro-gray-150 cursor-grab"
                        draggable="true"
                        @dragstart="onUserDragStart($event, user, group.id)"
                        @dragend="resetDrag">
                        <div class="h-6 w-6 rounded-full bg-gray-200 dark:bg-slate-700 flex items-center justify-center text-[10px] font-semibold ring-1 ring-gray-300 dark:ring-slate-700 shrink-0 overflow-hidden">
                            <img v-if="user.avatar" :src="user.avatar" class="object-cover w-full h-full" :alt="user.name" />
                            <span v-else>{{ initials(user.name) }}</span>
                        </div>
                        <span class="flex-1 text-xs truncate text-lavoro-dark">{{ user.name }}</span>
                        <input
                            type="checkbox"
                            :checked="user.plannable"
                            class="h-3.5 w-3.5 rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer"
                            :title="user.plannable ? 'Inplanbaar — klik om uit te zetten' : 'Niet inplanbaar — klik om aan te zetten'"
                            @change="$emit('plannable-toggled', user.id, $event.target.checked)" />
                    </div>
                    <div v-if="!usersInGroup(group.id).length"
                        class="px-3 py-1.5 text-[10px] text-gray-400 italic">
                        Sleep een monteur hierheen
                    </div>
                </div>
            </div>

            <!-- Geen groep section -->
            <div
                class="border-b border-b-lavoro-gray-150"
                @dragover.prevent="onDragOver($event, null)"
                @dragleave.self="dropTargetId = undefined"
                @drop.prevent="onDrop($event, null)">

                <div
                    class="flex items-center gap-2 px-3 py-2 select-none transition-colors"
                    :class="dropTargetId === null && activeType === 'user' ? 'bg-gray-50 dark:bg-slate-800' : ''">
                    <span class="h-3.5 w-3.5 rounded-sm bg-gray-300 dark:bg-slate-600 shrink-0" />
                    <span class="flex-1 text-xs font-medium text-gray-400 truncate">Geen groep</span>
                    <span class="text-[10px] text-gray-400 shrink-0">{{ ungroupedUsers.length }}</span>
                    <button class="text-gray-400 shrink-0" @click="toggleCollapse('ungrouped')">
                        <ChevronDownIcon v-if="!collapsedGroups.has('ungrouped')" class="size-3.5" />
                        <ChevronRightIcon v-else class="size-3.5" />
                    </button>
                </div>

                <div v-if="!collapsedGroups.has('ungrouped')">
                    <div
                        v-for="user in ungroupedUsers"
                        :key="user.id"
                        class="flex items-center gap-2 px-3 py-1.5 border-t border-t-lavoro-gray-150 cursor-grab"
                        draggable="true"
                        @dragstart="onUserDragStart($event, user, null)"
                        @dragend="resetDrag">
                        <div class="h-6 w-6 rounded-full bg-gray-200 dark:bg-slate-700 flex items-center justify-center text-[10px] font-semibold ring-1 ring-gray-300 dark:ring-slate-700 shrink-0 overflow-hidden">
                            <img v-if="user.avatar" :src="user.avatar" class="object-cover w-full h-full" :alt="user.name" />
                            <span v-else>{{ initials(user.name) }}</span>
                        </div>
                        <span class="flex-1 text-xs truncate text-lavoro-dark">{{ user.name }}</span>
                        <input
                            type="checkbox"
                            :checked="user.plannable"
                            class="h-3.5 w-3.5 rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer"
                            :title="user.plannable ? 'Inplanbaar — klik om uit te zetten' : 'Niet inplanbaar — klik om aan te zetten'"
                            @change="$emit('plannable-toggled', user.id, $event.target.checked)" />
                    </div>
                    <div v-if="!ungroupedUsers.length"
                        class="px-3 py-1.5 text-[10px] text-gray-400 italic">
                        Alle monteurs zijn ingedeeld
                    </div>
                </div>
            </div>
        </div>
    </BoxComponent>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import {
    PlusIcon, TrashIcon, ChevronDownIcon, ChevronRightIcon, Bars3Icon,
} from '@heroicons/vue/24/outline'
import { initials } from '@/Utilities/Utilities'

const props = defineProps({
    planGroups: { type: Array, default: () => [] },
    allUsers:   { type: Array, default: () => [] },
})

const emit = defineEmits([
    'group-created',
    'group-updated',
    'group-deleted',
    'group-reordered',
    'user-assigned',
    'user-unassigned',
    'plannable-toggled',
])

// UI state
const newGroup        = ref(null)
const newNameInput    = ref(null)
const editingGroupId  = ref(null)
const editingName     = ref('')
const editNameInputEl = ref(null)
const collapsedGroups = ref(new Set())

// Drag state
const activeType         = ref(null)   // 'group' | 'user'
const dropTargetId       = ref(undefined) // undefined=none, null="geen groep", number=groupId
const draggingUserId     = ref(null)
const draggingFromGroupId = ref(null)

// Computed
function usersInGroup(groupId) {
    return [...props.allUsers]
        .filter(u => u.plan_group_id === groupId)
        .sort((a, b) => a.name.localeCompare(b.name))
}

const ungroupedUsers = computed(() =>
    [...props.allUsers]
        .filter(u => u.plan_group_id == null)
        .sort((a, b) => a.name.localeCompare(b.name))
)

// New group
function startNewGroup() {
    newGroup.value = { name: '', color: '#2563ff' }
    nextTick(() => newNameInput.value?.focus())
}

function confirmNewGroup() {
    if (!newGroup.value?.name?.trim()) { newGroup.value = null; return }
    emit('group-created', { name: newGroup.value.name.trim(), color: newGroup.value.color })
    newGroup.value = null
}

// Edit name
function startEditName(group) {
    editingGroupId.value = group.id
    editingName.value = group.name
    nextTick(() => {
        editNameInputEl.value?.focus()
        editNameInputEl.value?.select()
    })
}

function confirmEditName(group) {
    const trimmed = editingName.value.trim()
    if (trimmed && trimmed !== group.name) {
        emit('group-updated', group.id, { name: trimmed })
    }
    editingGroupId.value = null
}

// Delete
function deleteGroup(group) {
    const count = usersInGroup(group.id).length
    if (count > 0 && !window.confirm(`Groep "${group.name}" heeft ${count} monteur(s). Weet je zeker dat je wilt verwijderen?`)) return
    emit('group-deleted', group.id)
}

// Collapse
function toggleCollapse(key) {
    const next = new Set(collapsedGroups.value)
    next.has(key) ? next.delete(key) : next.add(key)
    collapsedGroups.value = next
}

// Drag — groups (reorder)
function onGroupDragStart(e, group) {
    activeType.value = 'group'
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.setData('application/x-plan-group', String(group.id))
}

// Drag — users (assignment)
function onUserDragStart(e, user, fromGroupId) {
    activeType.value = 'user'
    draggingUserId.value = user.id
    draggingFromGroupId.value = fromGroupId
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.setData('application/x-plan-group-user', JSON.stringify({ userId: user.id, fromGroupId }))
    e.stopPropagation()
}

function resetDrag() {
    activeType.value = null
    dropTargetId.value = undefined
    draggingUserId.value = null
    draggingFromGroupId.value = null
}

// Drop target hover
function onDragOver(e, targetId) {
    if (e.dataTransfer.types.includes('application/x-plan-group-user')) {
        e.dataTransfer.dropEffect = 'move'
        dropTargetId.value = targetId
    } else if (e.dataTransfer.types.includes('application/x-plan-group')) {
        e.dataTransfer.dropEffect = 'move'
        dropTargetId.value = targetId
    }
}

// Drop
function onDrop(e, targetId) {
    dropTargetId.value = undefined

    if (e.dataTransfer.types.includes('application/x-plan-group-user')) {
        let payload
        try { payload = JSON.parse(e.dataTransfer.getData('application/x-plan-group-user')) } catch { return }
        const { userId, fromGroupId } = payload
        if (targetId === fromGroupId) return
        if (targetId == null) {
            emit('user-unassigned', userId)
        } else {
            emit('user-assigned', targetId, userId)
        }
        return
    }

    if (e.dataTransfer.types.includes('application/x-plan-group')) {
        const draggedId = parseInt(e.dataTransfer.getData('application/x-plan-group'), 10)
        if (!draggedId || draggedId === targetId || targetId == null) return
        const ids = props.planGroups.map(g => g.id)
        const fromIdx = ids.indexOf(draggedId)
        const toIdx = ids.indexOf(targetId)
        if (fromIdx === -1 || toIdx === -1) return
        const newIds = [...ids]
        newIds.splice(fromIdx, 1)
        newIds.splice(toIdx, 0, draggedId)
        emit('group-reordered', newIds)
    }
}
</script>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/Planner/PlanGroupsWidget.vue
git commit -m "feat(plan-groups): add PlanGroupsWidget component"
```

---

### Task 9: ResourcePlannerWidget.vue — sorting + group bar overlay

**Files:**
- Modify: `resources/js/Components/Planner/ResourcePlannerWidget.vue`

- [ ] **Step 1: Add `groups` prop**

In the `defineProps` block, add after `rowHeight`:

```js
groups: { type: Array, default: () => [] },
```

- [ ] **Step 2: Replace the `visibleUsers` computed**

Replace:

```js
const visibleUsers = computed(() =>
    hasPermission('event.see_all')
        ? props.plannableUsers
        : props.plannableUsers.filter(u => u.id === authUserId.value)
)
```

With:

```js
const visibleUsers = computed(() => {
    const base = hasPermission('event.see_all')
        ? props.plannableUsers
        : props.plannableUsers.filter(u => u.id === authUserId.value)

    const groupIndex = Object.fromEntries(props.groups.map((g, i) => [g.id, i]))

    return [...base].sort((a, b) => {
        const ga = a.plan_group_id != null ? (groupIndex[a.plan_group_id] ?? Infinity) : Infinity
        const gb = b.plan_group_id != null ? (groupIndex[b.plan_group_id] ?? Infinity) : Infinity
        if (ga !== gb) return ga - gb
        return a.name.localeCompare(b.name)
    })
})
```

- [ ] **Step 3: Add `groupBars` computed** (place after `visibleUsers`)

```js
const groupBars = computed(() => {
    const users = visibleUsers.value
    if (!users.length) return []

    let top = allDayLaneHeight.value
    const segments = new Map()

    for (const user of users) {
        const rowH = rowHeightFor(user.id)
        const key = user.plan_group_id != null ? user.plan_group_id : 'ungrouped'

        if (!segments.has(key)) {
            let color = '#9ca3af'
            let name = 'Geen groep'
            if (user.plan_group_id != null) {
                const group = props.groups.find(g => g.id === user.plan_group_id)
                if (group) { color = group.color; name = group.name }
            }
            segments.set(key, { top, height: 0, color, name })
        }
        segments.get(key).height += rowH
        top += rowH
    }

    return [...segments.values()]
})
```

- [ ] **Step 4: Add `relative` class and bar overlay to the scroll container**

Find this line in the template (around line 53):

```html
<div class="flex-1 overflow-y-auto" ref="sidebarScrollRef">
```

Change it to:

```html
<div class="flex-1 overflow-y-auto relative" ref="sidebarScrollRef">
```

Then immediately after that opening `<div>`, before the allday `<div>`, add the overlay:

```html
<!-- Group bar overlay -->
<div class="absolute top-0 left-0 pointer-events-none" style="width: 0; overflow: visible; z-index: 1;">
    <div
        v-for="(bar, i) in groupBars"
        :key="i"
        class="absolute left-0 w-1 rounded-sm flex items-start overflow-hidden"
        :style="{ top: bar.top + 'px', height: bar.height + 'px', background: bar.color }">
        <span
            class="text-[9px] font-semibold text-white select-none px-px"
            style="writing-mode: vertical-rl; transform: rotate(180deg); line-height: 1rem;">
            {{ bar.name }}
        </span>
    </div>
</div>
```

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Planner/ResourcePlannerWidget.vue
git commit -m "feat(plan-groups): sort users by group, add colored group bar overlay to sidebar"
```

---

### Task 10: IndexPage.vue — reactive state, sidebar, event handlers

**Files:**
- Modify: `resources/js/Pages/Planner/IndexPage.vue`

- [ ] **Step 1: Replace the full file**

```vue
<template>
    <!-- Mobile (below md) -->
    <div class="md:hidden h-screen overflow-hidden">
        <MobilePlannerView
            :event-types="eventTypes"
            :all-customers="allCustomers"
            :customers-use-ajax="customersUseAjax"
            :all-service-orders="allServiceOrders"
            :event-statusses="eventStatusses"
            :all-users="allUsers"
            :plannable-users="plannableUsersRef" />
    </div>

    <!-- Desktop (md and up) -->
    <div class="hidden md:grid grid-cols-12 gap-x-3 p-3">
        <div :class="showSidebar ? 'col-span-10' : 'col-span-12'">
            <BoxComponent padding="p-0">
                <ResourcePlannerWidget
                    :event-types="eventTypes"
                    :all-customers="allCustomers"
                    :customers-use-ajax="customersUseAjax"
                    :all-service-orders="allServiceOrders"
                    :event-statusses="eventStatusses"
                    :all-users="allUsers"
                    :plannable-users="plannableUsersRef"
                    :projects="projects"
                    :groups="planGroupsRef"
                    @service-order-planned="onServiceOrderPlanned"
                    @service-order-unplanned="onServiceOrderUnplanned" />
            </BoxComponent>
        </div>
        <div v-if="showSidebar" class="col-span-2 flex flex-col gap-3">
            <BoxComponent v-if="canPlan" padding="p-0">
                <UnplannedServiceOrdersWidget :service-orders="unplanned" />
            </BoxComponent>
            <PlanGroupsWidget
                v-if="canManageGroups"
                :plan-groups="planGroupsRef"
                :all-users="allPlanUsersRef"
                @group-created="onGroupCreated"
                @group-updated="onGroupUpdated"
                @group-deleted="onGroupDeleted"
                @group-reordered="onGroupReordered"
                @user-assigned="onUserAssigned"
                @user-unassigned="onUserUnassigned"
                @plannable-toggled="onPlannableToggled" />
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import ResourcePlannerWidget from '@/Components/Planner/ResourcePlannerWidget.vue'
import UnplannedServiceOrdersWidget from '@/Components/Planner/UnplannedServiceOrdersWidget.vue'
import PlanGroupsWidget from '@/Components/Planner/PlanGroupsWidget.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import MobilePlannerView from '@/Components/Planner/MobilePlannerView.vue'
import { hasPermission } from '@/Utilities/Utilities'

const props = defineProps({
    eventTypes:              { type: Array, required: true },
    allCustomers:            { type: Array, required: true },
    customersUseAjax:        { type: Boolean, default: false },
    allServiceOrders:        { type: Array, required: true },
    eventStatusses:          { type: Array, required: true },
    allUsers:                { type: Array, required: true },
    plannableUsers:          { type: Array, required: true },
    unplannedServiceOrders:  { type: Array, default: () => [] },
    projects:                { type: Array, default: () => [] },
    planGroups:              { type: Array, default: () => [] },
    allPlanUsers:            { type: Array, default: () => [] },
})

const page = usePage()

// Reactive refs so widget mutations don't need a page reload
const planGroupsRef     = ref(props.planGroups)
const plannableUsersRef = ref(props.plannableUsers)
const allPlanUsersRef   = ref(props.allPlanUsers)

const canPlan          = computed(() => hasPermission('serviceorder.plan'))
const canManageGroups  = computed(() => hasPermission('event.see_all') || hasPermission('event.create_others'))
const showSidebar      = computed(() => canPlan.value || canManageGroups.value)

// Track planned service orders
const plannedIds = ref(new Set())

const unplanned = computed(() =>
    props.unplannedServiceOrders.filter(so => !plannedIds.value.has(so.id))
)

const projects = computed(() =>
    props.projects.map(p => ({
        ...p,
        service_orders: (p.service_orders || []).filter(so => !plannedIds.value.has(so.id)),
    }))
)

function onServiceOrderPlanned(id)   { plannedIds.value = new Set(plannedIds.value).add(id) }
function onServiceOrderUnplanned(id) { const s = new Set(plannedIds.value); s.delete(id); plannedIds.value = s }

// --- Group handlers ---

async function onGroupCreated(data) {
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.post('/api/plan-groups', data)
        planGroupsRef.value = [...planGroupsRef.value, { ...r.data, user_ids: [] }]
    } catch (e) {
        page.props.flash.error = e.response?.data?.message || 'Kon groep niet aanmaken'
    }
}

async function onGroupUpdated(id, patch) {
    const idx = planGroupsRef.value.findIndex(g => g.id === id)
    if (idx === -1) return
    const original = { ...planGroupsRef.value[idx] }
    planGroupsRef.value[idx] = { ...planGroupsRef.value[idx], ...patch }
    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.put(`/api/plan-groups/${id}`, patch)
    } catch (e) {
        planGroupsRef.value[idx] = original
        page.props.flash.error = e.response?.data?.message || 'Kon groep niet bijwerken'
    }
}

async function onGroupDeleted(id) {
    const originalGroups = [...planGroupsRef.value]
    planGroupsRef.value = planGroupsRef.value.filter(g => g.id !== id)
    allPlanUsersRef.value   = allPlanUsersRef.value.map(u => u.plan_group_id === id ? { ...u, plan_group_id: null } : u)
    plannableUsersRef.value = plannableUsersRef.value.map(u => u.plan_group_id === id ? { ...u, plan_group_id: null } : u)
    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.delete(`/api/plan-groups/${id}`)
    } catch (e) {
        planGroupsRef.value = originalGroups
        page.props.flash.error = e.response?.data?.message || 'Kon groep niet verwijderen'
    }
}

async function onGroupReordered(ids) {
    const original = [...planGroupsRef.value]
    const map = Object.fromEntries(planGroupsRef.value.map(g => [g.id, g]))
    planGroupsRef.value = ids.map(id => map[id]).filter(Boolean)
    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.put('/api/plan-groups/reorder', { ids })
    } catch (e) {
        planGroupsRef.value = original
        page.props.flash.error = e.response?.data?.message || 'Kon volgorde niet opslaan'
    }
}

async function onUserAssigned(groupId, userId) {
    const oldGroup    = planGroupsRef.value.find(g => g.user_ids.includes(userId))
    const targetGroup = planGroupsRef.value.find(g => g.id === groupId)
    if (!targetGroup) return

    if (oldGroup) oldGroup.user_ids = oldGroup.user_ids.filter(id => id !== userId)
    targetGroup.user_ids = [...targetGroup.user_ids, userId]
    allPlanUsersRef.value   = allPlanUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: groupId } : u)
    plannableUsersRef.value = plannableUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: groupId } : u)

    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.put(`/api/plan-groups/${groupId}/users/${userId}`)
    } catch (e) {
        if (oldGroup) oldGroup.user_ids = [...oldGroup.user_ids, userId]
        targetGroup.user_ids = targetGroup.user_ids.filter(id => id !== userId)
        const prev = oldGroup?.id ?? null
        allPlanUsersRef.value   = allPlanUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: prev } : u)
        plannableUsersRef.value = plannableUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: prev } : u)
        page.props.flash.error = e.response?.data?.message || 'Kon monteur niet toewijzen'
    }
}

async function onUserUnassigned(userId) {
    const oldGroup = planGroupsRef.value.find(g => g.user_ids.includes(userId))
    if (!oldGroup) return

    oldGroup.user_ids = oldGroup.user_ids.filter(id => id !== userId)
    allPlanUsersRef.value   = allPlanUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: null } : u)
    plannableUsersRef.value = plannableUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: null } : u)

    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.delete(`/api/plan-groups/${oldGroup.id}/users/${userId}`)
    } catch (e) {
        oldGroup.user_ids = [...oldGroup.user_ids, userId]
        allPlanUsersRef.value   = allPlanUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: oldGroup.id } : u)
        plannableUsersRef.value = plannableUsersRef.value.map(u => u.id === userId ? { ...u, plan_group_id: oldGroup.id } : u)
        page.props.flash.error = e.response?.data?.message || 'Kon monteur niet uit groep halen'
    }
}

async function onPlannableToggled(userId, value) {
    allPlanUsersRef.value = allPlanUsersRef.value.map(u => u.id === userId ? { ...u, plannable: value } : u)
    if (value) {
        const user = allPlanUsersRef.value.find(u => u.id === userId)
        if (user && !plannableUsersRef.value.find(u => u.id === userId)) {
            plannableUsersRef.value = [...plannableUsersRef.value, { ...user }]
        }
    } else {
        plannableUsersRef.value = plannableUsersRef.value.filter(u => u.id !== userId)
    }

    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.patch(`/api/users/${userId}/plannable`, { plannable: value })
    } catch (e) {
        allPlanUsersRef.value = allPlanUsersRef.value.map(u => u.id === userId ? { ...u, plannable: !value } : u)
        if (!value) {
            const user = allPlanUsersRef.value.find(u => u.id === userId)
            if (user && !plannableUsersRef.value.find(u => u.id === userId)) {
                plannableUsersRef.value = [...plannableUsersRef.value, { ...user }]
            }
        } else {
            plannableUsersRef.value = plannableUsersRef.value.filter(u => u.id !== userId)
        }
        page.props.flash.error = e.response?.data?.message || 'Kon inplanbaar-status niet bijwerken'
    }
}
</script>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/Planner/IndexPage.vue
git commit -m "feat(plan-groups): wire PlanGroupsWidget into planner IndexPage with reactive state"
```

---

## Self-Review

**Spec coverage:**
- ✅ `user_plan_groups` table with name, color, sort_order — Task 1
- ✅ `user_plan_group_id` FK on users — Task 2
- ✅ UserPlanGroup model + User relationship — Task 3
- ✅ Form Requests with event.see_all/event.create_others auth — Task 4
- ✅ Full CRUD + reorder + assign/remove user API — Task 5 + 6
- ✅ PlannerController passes plan_groups, allPlanUsers, plan_group_id — Task 7
- ✅ PlanGroupsWidget with drag-and-drop, inline edit, color picker, plannable toggle — Task 8
- ✅ Group bar overlay in ResourcePlannerWidget left sidebar — Task 9
- ✅ IndexPage reactive state, sidebar visibility, event handlers — Task 10
- ✅ "Geen groep" section at bottom, alphabetical — Task 8 (ungroupedUsers computed)
- ✅ Reorder route registered before {group} wildcard — Task 6
- ✅ lavoro-blue / lavoro-dark styling throughout widget — Task 8

**Placeholder scan:** None found. All steps contain complete code.

**Type consistency:**
- `plan_group_id` used consistently (not `planGroupId`) throughout backend maps and frontend props
- `planGroupsRef` / `allPlanUsersRef` / `plannableUsersRef` used consistently in Task 10
- `groupBars` computed in Task 9 references `allDayLaneHeight` which exists in scope ✅
- `usersInGroup(group.id)` in Task 8 is a plain function (not computed) — correct for use in template loops ✅
