# User Roster / Unavailabilities Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add roster management (recurring off-days and full-day holidays) for plannable users, with visual blocking of those periods in the resource planner that prevents any scheduling on them.

**Architecture:** A single `user_unavailabilities` table stores both recurring day-of-week rules (weekly/biweekly) and full-day holidays. `UserUnavailability::expandForWeek()` converts recurring rules into concrete date blocks for a given week. A Sanctum API endpoint expands and serves all blocks to the planner. Management CRUD lives on the user edit page via web routes, secured by `UserUnavailabilityPolicy`.

**Tech Stack:** Laravel 12, Inertia.js, Vue 3, Axios, Carbon, Headless UI (SelectMenuComponent)

---

## File Map

**Create:**
- `database/migrations/2026_06_04_000005_create_user_unavailabilities_table.php`
- `database/migrations/2026_06_04_000006_add_roster_permissions.php`
- `app/Models/UserUnavailability.php`
- `app/Policies/UserUnavailabilityPolicy.php`
- `app/Http/Requests/UserUnavailabilityStoreRequest.php`
- `app/Http/Requests/UserUnavailabilityDestroyRequest.php`
- `app/Http/Controllers/UserUnavailabilityController.php`
- `app/Http/Controllers/UnavailabilityApiController.php`
- `resources/js/Components/Users/UserRosterWidget.vue`

**Modify:**
- `app/Models/User.php` — add `unavailabilities()` hasMany
- `app/Providers/AppServiceProvider.php` — register policy
- `app/Http/Controllers/UserController.php` — add `unavailabilities` prop to `edit` and `editSelf`
- `routes/web.php` — add management routes (under `auth`, not `admin`)
- `routes/api.php` — add planner read endpoint
- `resources/js/Pages/Users/EditPage.vue` — import and render `UserRosterWidget`
- `resources/js/Components/Planner/ResourcePlannerWidget.vue` — fetch blocks, render overlays, block interaction

---

## Task 1: Migration — create user_unavailabilities table

**Files:**
- Create: `database/migrations/2026_06_04_000005_create_user_unavailabilities_table.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_unavailabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\User::class)->constrained()->cascadeOnDelete();
            $table->enum('type', ['recurring', 'holiday']);
            $table->string('label')->nullable();
            $table->tinyInteger('day_of_week')->nullable(); // 0=Mon, 6=Sun
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->enum('repeat', ['weekly', 'biweekly'])->nullable();
            $table->date('reference_date')->nullable(); // biweekly anchor
            $table->date('date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_unavailabilities');
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_06_04_000005_create_user_unavailabilities_table` → `Migrated`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_04_000005_create_user_unavailabilities_table.php
git commit -m "feat(roster): create user_unavailabilities table"
```

---

## Task 2: Migration — seed roster permissions

**Files:**
- Create: `database/migrations/2026_06_04_000006_add_roster_permissions.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->upsert([
            [
                'name'        => 'roster.manage_own',
                'description' => 'Eigen rooster beheren',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'name'        => 'roster.manage_all',
                'description' => 'Rooster van alle gebruikers beheren',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ], ['name'], ['description', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('name', ['roster.manage_own', 'roster.manage_all'])
            ->delete();
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_06_04_000006_add_roster_permissions` → `Migrated`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_04_000006_add_roster_permissions.php
git commit -m "feat(roster): seed roster.manage_own and roster.manage_all permissions"
```

---

## Task 3: UserUnavailability model

**Files:**
- Create: `app/Models/UserUnavailability.php`

- [ ] **Step 1: Create the model**

```php
<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserUnavailability extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'label',
        'day_of_week',
        'start_time',
        'end_time',
        'repeat',
        'reference_date',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'date'           => 'date',
            'reference_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForWeek(Builder $query, Carbon $weekStart, Carbon $weekEnd): Builder
    {
        return $query->where(function ($q) use ($weekStart, $weekEnd) {
            $q->where('type', 'holiday')
              ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()]);
        })->orWhere('type', 'recurring');
    }

    public function expandForWeek(Carbon $weekStart, Carbon $weekEnd): array
    {
        if ($this->type === 'holiday') {
            return [[
                'user_id'    => $this->user_id,
                'date'       => $this->date->toDateString(),
                'start_time' => null,
                'end_time'   => null,
                'label'      => $this->label,
            ]];
        }

        $blocks  = [];
        $current = $weekStart->copy()->startOfDay();

        while ($current->lte($weekEnd)) {
            // Carbon dayOfWeekIso: 1=Mon..7=Sun → convert to 0=Mon..6=Sun
            $dow = $current->dayOfWeekIso - 1;

            if ($dow === (int) $this->day_of_week) {
                $include = false;

                if ($this->repeat === 'weekly') {
                    $include = true;
                } elseif ($this->repeat === 'biweekly' && $this->reference_date !== null) {
                    // Week 0 (the reference week itself) is the first occurrence.
                    $weeksDiff = (int) $this->reference_date->diffInWeeks($current);
                    $include   = $weeksDiff % 2 === 0;
                }

                if ($include) {
                    $blocks[] = [
                        'user_id'    => $this->user_id,
                        'date'       => $current->toDateString(),
                        'start_time' => $this->start_time,
                        'end_time'   => $this->end_time,
                        'label'      => $this->label,
                    ];
                }
            }

            $current->addDay();
        }

        return $blocks;
    }
}
```

- [ ] **Step 2: Verify no syntax errors**

```bash
php artisan tinker --execute="new \App\Models\UserUnavailability; echo 'ok';"
```

Expected: `ok`

- [ ] **Step 3: Commit**

```bash
git add app/Models/UserUnavailability.php
git commit -m "feat(roster): UserUnavailability model with expandForWeek"
```

---

## Task 4: User model — add unavailabilities relationship

**Files:**
- Modify: `app/Models/User.php`

- [ ] **Step 1: Add the hasMany relationship to User**

In `app/Models/User.php`, after the last relationship method (e.g. after `calendarGrantsReceived()`), add:

```php
public function unavailabilities(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(UserUnavailability::class);
}
```

- [ ] **Step 2: Verify**

```bash
php artisan tinker --execute="\$u = \App\Models\User::first(); echo \$u->unavailabilities()->count();"
```

Expected: `0` (table exists and relationship resolves)

- [ ] **Step 3: Commit**

```bash
git add app/Models/User.php
git commit -m "feat(roster): add unavailabilities() relationship to User"
```

---

## Task 5: UserUnavailabilityPolicy + register

**Files:**
- Create: `app/Policies/UserUnavailabilityPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Create the policy**

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserUnavailability;

class UserUnavailabilityPolicy
{
    public function create(User $auth_user, User $target_user): bool
    {
        return $auth_user->hasPermission('roster.manage_all')
            || ($auth_user->hasPermission('roster.manage_own') && $auth_user->id === $target_user->id);
    }

    public function delete(User $auth_user, UserUnavailability $unavailability): bool
    {
        return $auth_user->hasPermission('roster.manage_all')
            || ($auth_user->hasPermission('roster.manage_own') && $auth_user->id === $unavailability->user_id);
    }
}
```

- [ ] **Step 2: Register the policy in AppServiceProvider**

In `app/Providers/AppServiceProvider.php`, add two lines to the import block at the top:

```php
use App\Models\UserUnavailability;
use App\Policies\UserUnavailabilityPolicy;
```

Then in `boot()`, after the existing `Gate::policy` calls (lines 36–37), add:

```php
Gate::policy(UserUnavailability::class, UserUnavailabilityPolicy::class);
```

- [ ] **Step 3: Verify policy resolves**

```bash
php artisan tinker --execute="echo app(\Illuminate\Contracts\Auth\Access\Gate::class)->getPolicyFor(\App\Models\UserUnavailability::class)::class;"
```

Expected: `App\Policies\UserUnavailabilityPolicy`

- [ ] **Step 4: Commit**

```bash
git add app/Policies/UserUnavailabilityPolicy.php app/Providers/AppServiceProvider.php
git commit -m "feat(roster): UserUnavailabilityPolicy and registration"
```

---

## Task 6: Form Requests

**Files:**
- Create: `app/Http/Requests/UserUnavailabilityStoreRequest.php`
- Create: `app/Http/Requests/UserUnavailabilityDestroyRequest.php`

- [ ] **Step 1: Create UserUnavailabilityStoreRequest**

```php
<?php

namespace App\Http\Requests;

use App\Models\UserUnavailability;
use Illuminate\Foundation\Http\FormRequest;

class UserUnavailabilityStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [UserUnavailability::class, $this->route('user')]);
    }

    public function rules(): array
    {
        return [
            'type'           => 'required|in:recurring,holiday',
            'label'          => 'nullable|string|max:255',
            'day_of_week'    => 'required_if:type,recurring|nullable|integer|between:0,6',
            'start_time'     => 'required_if:type,recurring|nullable|date_format:H:i',
            'end_time'       => 'required_if:type,recurring|nullable|date_format:H:i|after:start_time',
            'repeat'         => 'required_if:type,recurring|nullable|in:weekly,biweekly',
            'reference_date' => 'required_if:repeat,biweekly|nullable|date',
            'date'           => 'required_if:type,holiday|nullable|date',
        ];
    }
}
```

- [ ] **Step 2: Create UserUnavailabilityDestroyRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserUnavailabilityDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('unavailability'));
    }

    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 3: Verify no syntax errors**

```bash
php artisan route:list 2>&1 | head -5
```

Expected: No PHP parse errors, routes list begins normally.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Requests/UserUnavailabilityStoreRequest.php app/Http/Requests/UserUnavailabilityDestroyRequest.php
git commit -m "feat(roster): UserUnavailabilityStoreRequest and DestroyRequest"
```

---

## Task 7: UserUnavailabilityController (web management)

**Files:**
- Create: `app/Http/Controllers/UserUnavailabilityController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserUnavailabilityDestroyRequest;
use App\Http\Requests\UserUnavailabilityStoreRequest;
use App\Models\User;
use App\Models\UserUnavailability;

class UserUnavailabilityController extends Controller
{
    public function store(UserUnavailabilityStoreRequest $request, User $user): \Illuminate\Http\RedirectResponse
    {
        $user->unavailabilities()->create($request->validated());

        return back();
    }

    public function destroy(UserUnavailabilityDestroyRequest $request, User $user, UserUnavailability $unavailability): \Illuminate\Http\RedirectResponse
    {
        $unavailability->delete();

        return back();
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/UserUnavailabilityController.php
git commit -m "feat(roster): UserUnavailabilityController store and destroy"
```

---

## Task 8: UnavailabilityApiController (planner read endpoint)

**Files:**
- Create: `app/Http/Controllers/UnavailabilityApiController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\UserUnavailability;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnavailabilityApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $start = Carbon::parse($request->query('start'))->startOfDay();
        $end   = Carbon::parse($request->query('end'))->endOfDay();

        $blocks = UserUnavailability::forWeek($start, $end)
            ->get()
            ->flatMap(fn ($entry) => $entry->expandForWeek($start, $end))
            ->values();

        return response()->json($blocks);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/UnavailabilityApiController.php
git commit -m "feat(roster): UnavailabilityApiController index"
```

---

## Task 9: Routes

**Files:**
- Modify: `routes/web.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Add management routes to web.php**

In `routes/web.php`, after line 188 (`Route::post('me', ...)`), inside the `auth` middleware group but **outside** the nested `admin` group, add:

```php
Route::post('users/{user}/unavailabilities', [UserUnavailabilityController::class, 'store'])
    ->name('users.unavailabilities.store');
Route::delete('users/{user}/unavailabilities/{unavailability}', [UserUnavailabilityController::class, 'destroy'])
    ->name('users.unavailabilities.destroy');
```

Also add the import at the top of the file with the other use statements:

```php
use App\Http\Controllers\UserUnavailabilityController;
```

- [ ] **Step 2: Add API route to api.php**

In `routes/api.php`, inside the `auth:sanctum` group, add after the existing routes:

```php
Route::get('unavailabilities', [UnavailabilityApiController::class, 'index']);
```

Also add the import:

```php
use App\Http\Controllers\UnavailabilityApiController;
```

- [ ] **Step 3: Verify routes are registered**

```bash
php artisan route:list --name=users.unavailabilities
```

Expected output includes two routes: `POST users/{user}/unavailabilities` and `DELETE users/{user}/unavailabilities/{unavailability}`

```bash
php artisan route:list --path=api/unavailabilities
```

Expected: `GET api/unavailabilities`

- [ ] **Step 4: Commit**

```bash
git add routes/web.php routes/api.php
git commit -m "feat(roster): register management and API routes for unavailabilities"
```

---

## Task 10: UserController — add unavailabilities prop

**Files:**
- Modify: `app/Http/Controllers/UserController.php`

- [ ] **Step 1: Update the edit() method**

Replace the `edit(User $user)` method body:

```php
public function edit(User $user)
{
    $user->load('roles:id,name');
    return inertia('Users/EditPage', [
        'user'             => $user,
        'allRoles'         => Role::orderBy('name')->get(['id', 'name']),
        'unavailabilities' => $user->unavailabilities()
            ->orderBy('type')
            ->orderBy('day_of_week')
            ->orderBy('date')
            ->get(),
    ]);
}
```

- [ ] **Step 2: Update the editSelf() method**

Replace the `editSelf()` method body:

```php
public function editSelf()
{
    $user = request()->user();
    abort_unless($user, 403);
    $user->load('roles:id,name');
    return inertia('Users/EditPage', [
        'user'             => $user,
        'allRoles'         => $user->isAdmin() ? Role::orderBy('name')->get(['id', 'name']) : [],
        'unavailabilities' => $user->unavailabilities()
            ->orderBy('type')
            ->orderBy('day_of_week')
            ->orderBy('date')
            ->get(),
    ]);
}
```

- [ ] **Step 3: Verify page loads**

```bash
php artisan route:list --name=users.edit
```

Visit `/users/{any-id}/edit` in the browser — page should load without errors (check the network tab, unavailabilities should be in the Inertia props as an empty array if no entries exist yet).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/UserController.php
git commit -m "feat(roster): pass unavailabilities prop to user edit pages"
```

---

## Task 11: UserRosterWidget.vue

**Files:**
- Create: `resources/js/Components/Users/UserRosterWidget.vue`

The `SelectMenuComponent` accepts `options` as `Array<{ value, title, shortTitle?, description? }>` and uses `v-model` bound to the option's `value` field.

- [ ] **Step 1: Create the component**

```vue
<script setup>
import { ref, computed } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { hasPermission } from '@/Utilities/Utilities.js'
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue'

const props = defineProps({
    user: { type: Object, required: true },
    unavailabilities: { type: Array, default: () => [] },
})

const page = usePage()

const canManage = computed(() =>
    hasPermission('roster.manage_all') ||
    (hasPermission('roster.manage_own') && props.user.id === page.props.auth.user.id)
)

const DAY_OPTIONS = [
    { value: 0, title: 'Maandag' },
    { value: 1, title: 'Dinsdag' },
    { value: 2, title: 'Woensdag' },
    { value: 3, title: 'Donderdag' },
    { value: 4, title: 'Vrijdag' },
    { value: 5, title: 'Zaterdag' },
    { value: 6, title: 'Zondag' },
]

const REPEAT_OPTIONS = [
    { value: 'weekly',   title: 'Wekelijks' },
    { value: 'biweekly', title: 'Om de week' },
]

const showRecurringForm = ref(false)
const showHolidayForm   = ref(false)

const recurringForm = useForm({
    type:           'recurring',
    day_of_week:    null,
    start_time:     '',
    end_time:       '',
    repeat:         'weekly',
    reference_date: '',
    label:          '',
})

const holidayForm = useForm({
    type:  'holiday',
    date:  '',
    label: '',
})

const recurringEntries = computed(() => props.unavailabilities.filter(u => u.type === 'recurring'))
const holidayEntries   = computed(() => props.unavailabilities.filter(u => u.type === 'holiday'))

function dayLabel(dow) {
    return DAY_OPTIONS.find(d => d.value === dow)?.title ?? String(dow)
}

function submitRecurring() {
    recurringForm.post(route('users.unavailabilities.store', { user: props.user.id }), {
        preserveScroll: true,
        only: ['unavailabilities'],
        onSuccess: () => {
            showRecurringForm.value = false
            recurringForm.reset()
        },
    })
}

function submitHoliday() {
    holidayForm.post(route('users.unavailabilities.store', { user: props.user.id }), {
        preserveScroll: true,
        only: ['unavailabilities'],
        onSuccess: () => {
            showHolidayForm.value = false
            holidayForm.reset()
        },
    })
}

function destroy(id) {
    router.delete(
        route('users.unavailabilities.destroy', { user: props.user.id, unavailability: id }),
        { preserveScroll: true, only: ['unavailabilities'] }
    )
}
</script>

<template>
    <div v-if="canManage" class="space-y-6">

        <!-- Recurring off-periods -->
        <div>
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Vaste vrije momenten</h3>

            <div v-if="recurringEntries.length"
                 class="divide-y divide-gray-100 dark:divide-slate-700 border border-gray-200 dark:border-slate-700 rounded mb-3">
                <div v-for="entry in recurringEntries" :key="entry.id"
                     class="flex items-center justify-between px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                    <span>
                        {{ dayLabel(entry.day_of_week) }},
                        {{ entry.start_time?.slice(0, 5) }} – {{ entry.end_time?.slice(0, 5) }}
                        <span class="text-gray-400 ml-1">
                            ({{ entry.repeat === 'weekly' ? 'wekelijks' : 'om de week' }})
                        </span>
                        <span v-if="entry.label" class="text-gray-400 ml-1">— {{ entry.label }}</span>
                    </span>
                    <button type="button" @click="destroy(entry.id)"
                            class="ml-3 text-red-400 hover:text-red-600 text-base leading-none">×</button>
                </div>
            </div>
            <p v-else class="text-sm text-gray-400 mb-3">Geen vaste vrije momenten.</p>

            <button v-if="!showRecurringForm" type="button"
                    @click="showRecurringForm = true"
                    class="text-sm text-blue-600 hover:underline">+ Toevoegen</button>

            <form v-else @submit.prevent="submitRecurring"
                  class="space-y-3 border border-gray-200 dark:border-slate-600 rounded p-3 bg-gray-50 dark:bg-slate-800/50">

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Dag</label>
                        <SelectMenuComponent v-model="recurringForm.day_of_week"
                                             :options="DAY_OPTIONS" label="Kies dag" />
                        <p v-if="recurringForm.errors.day_of_week" class="text-xs text-red-500 mt-1">
                            {{ recurringForm.errors.day_of_week }}
                        </p>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Herhaling</label>
                        <SelectMenuComponent v-model="recurringForm.repeat"
                                             :options="REPEAT_OPTIONS" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Van</label>
                        <input v-model="recurringForm.start_time" type="time"
                               class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm py-2 px-3" />
                        <p v-if="recurringForm.errors.start_time" class="text-xs text-red-500 mt-1">
                            {{ recurringForm.errors.start_time }}
                        </p>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Tot</label>
                        <input v-model="recurringForm.end_time" type="time"
                               class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm py-2 px-3" />
                        <p v-if="recurringForm.errors.end_time" class="text-xs text-red-500 mt-1">
                            {{ recurringForm.errors.end_time }}
                        </p>
                    </div>
                </div>

                <div v-if="recurringForm.repeat === 'biweekly'">
                    <label class="block text-xs text-gray-500 mb-1">Eerste keer (ankerdatum)</label>
                    <input v-model="recurringForm.reference_date" type="date"
                           class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm py-2 px-3" />
                    <p v-if="recurringForm.errors.reference_date" class="text-xs text-red-500 mt-1">
                        {{ recurringForm.errors.reference_date }}
                    </p>
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Label (optioneel)</label>
                    <input v-model="recurringForm.label" type="text" placeholder="bijv. Parttime dag"
                           class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm py-2 px-3" />
                </div>

                <div class="flex gap-2">
                    <button type="submit" :disabled="recurringForm.processing"
                            class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                        Opslaan
                    </button>
                    <button type="button"
                            @click="showRecurringForm = false; recurringForm.clearErrors()"
                            class="px-3 py-1.5 text-sm text-gray-500 hover:text-gray-700">
                        Annuleren
                    </button>
                </div>
            </form>
        </div>

        <!-- Holidays -->
        <div>
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Vrije dagen</h3>

            <div v-if="holidayEntries.length"
                 class="divide-y divide-gray-100 dark:divide-slate-700 border border-gray-200 dark:border-slate-700 rounded mb-3">
                <div v-for="entry in holidayEntries" :key="entry.id"
                     class="flex items-center justify-between px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                    <span>
                        {{ entry.date }}
                        <span v-if="entry.label" class="text-gray-400 ml-1">— {{ entry.label }}</span>
                    </span>
                    <button type="button" @click="destroy(entry.id)"
                            class="ml-3 text-red-400 hover:text-red-600 text-base leading-none">×</button>
                </div>
            </div>
            <p v-else class="text-sm text-gray-400 mb-3">Geen vrije dagen.</p>

            <button v-if="!showHolidayForm" type="button"
                    @click="showHolidayForm = true"
                    class="text-sm text-blue-600 hover:underline">+ Toevoegen</button>

            <form v-else @submit.prevent="submitHoliday"
                  class="space-y-3 border border-gray-200 dark:border-slate-600 rounded p-3 bg-gray-50 dark:bg-slate-800/50">

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Datum</label>
                    <input v-model="holidayForm.date" type="date"
                           class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm py-2 px-3" />
                    <p v-if="holidayForm.errors.date" class="text-xs text-red-500 mt-1">
                        {{ holidayForm.errors.date }}
                    </p>
                </div>

                <div>
                    <label class="block text-xs text-gray-500 mb-1">Label (optioneel)</label>
                    <input v-model="holidayForm.label" type="text" placeholder="bijv. Vakantie"
                           class="block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-700 text-sm py-2 px-3" />
                </div>

                <div class="flex gap-2">
                    <button type="submit" :disabled="holidayForm.processing"
                            class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                        Opslaan
                    </button>
                    <button type="button"
                            @click="showHolidayForm = false; holidayForm.clearErrors()"
                            class="px-3 py-1.5 text-sm text-gray-500 hover:text-gray-700">
                        Annuleren
                    </button>
                </div>
            </form>
        </div>

    </div>
</template>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/Users/UserRosterWidget.vue
git commit -m "feat(roster): UserRosterWidget component"
```

---

## Task 12: EditPage.vue — add UserRosterWidget

**Files:**
- Modify: `resources/js/Pages/Users/EditPage.vue`

The page already receives a `user` prop. The `unavailabilities` prop is now passed from the controller.

- [ ] **Step 1: Add the prop and import**

At the top of the `<script setup>` block, add the import:

```javascript
import UserRosterWidget from '@/Components/Users/UserRosterWidget.vue'
```

Add `unavailabilities` to the `defineProps` call (find the existing `defineProps` in the file and add the property):

```javascript
const props = defineProps({
    user: Object,
    allRoles: Array,
    unavailabilities: { type: Array, default: () => [] },
})
```

- [ ] **Step 2: Add the widget to the template**

In the template, after the closing tag of the last form section (before `</template>`), add a new section:

```html
<!-- Roster -->
<div v-if="props.user" class="mt-8">
    <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-4">Rooster</h2>
    <UserRosterWidget :user="props.user" :unavailabilities="props.unavailabilities" />
</div>
```

- [ ] **Step 3: Verify in browser**

Open `/users/{any-user-id}/edit` as an admin. Confirm the "Rooster" section appears at the bottom of the page. Confirm adding a recurring entry or holiday saves and reloads the list without a full page reload.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Users/EditPage.vue
git commit -m "feat(roster): add UserRosterWidget to user edit page"
```

---

## Task 13: ResourcePlannerWidget.vue — fetch blocks, render overlays, block interaction

**Files:**
- Modify: `resources/js/Components/Planner/ResourcePlannerWidget.vue`

The planner grid is horizontal (time flows left → right). Blocks render as absolutely positioned overlays with `left` and `width` percentages inside each user×day cell. Three interaction points need blocking: `onCellPointerDown` (click-drag-create), `onDragOver` (drag preview), and `onExternalDrop` (drop to create).

- [ ] **Step 1: Add unavailabilities ref and fetchUnavailabilities function**

After line 289 (`const events = ref([])`), add:

```javascript
const unavailabilities = ref([])
```

After the `fetchEvents` function (after line 540), add:

```javascript
async function fetchUnavailabilities() {
    try {
        const startParam = formatUtcDatetime(weekStart.value)
        const endParam   = formatUtcDatetime(dayjs(weekStart.value).add(7, 'day').toDate())
        const response = await axios.get(
            `/api/unavailabilities?start=${encodeURIComponent(startParam)}&end=${encodeURIComponent(endParam)}`
        )
        if (response.status === 200) {
            unavailabilities.value = response.data
        }
    } catch (e) {
        console.error('Failed to fetch unavailabilities', e)
    }
}
```

- [ ] **Step 2: Call fetchUnavailabilities alongside fetchEvents**

At line 468 (inside `onMounted`), the existing code calls `fetchEvents()`. Add `fetchUnavailabilities()` on the next line:

```javascript
onMounted(() => {
    // ... existing onMounted code ...
    fetchEvents()
    fetchUnavailabilities()
    // ...
})
```

At line 476, the existing watcher is `watch(weekStart, () => fetchEvents())`. Change it to:

```javascript
watch(weekStart, () => {
    fetchEvents()
    fetchUnavailabilities()
})
```

- [ ] **Step 3: Add isBlockedAtTime helper and getBlockOverlays helper**

After `fetchUnavailabilities`, add these two functions:

```javascript
function isBlockedAtTime(userId, dayIso, startMin, endMin) {
    return unavailabilities.value.some(b => {
        if (b.user_id !== userId || b.date !== dayIso) return false
        if (b.start_time === null) return true // full day holiday
        const [sh, sm] = b.start_time.split(':').map(Number)
        const [eh, em] = b.end_time.split(':').map(Number)
        return startMin < eh * 60 + em && endMin > sh * 60 + sm
    })
}

function getBlockOverlays(userId, dayIso) {
    const totalMin = (dayEndHour.value - dayStartHour.value) * 60
    return unavailabilities.value
        .filter(b => b.user_id === userId && b.date === dayIso)
        .map(b => {
            if (b.start_time === null) {
                return { left: 0, width: 100, label: b.label }
            }
            const [sh, sm] = b.start_time.split(':').map(Number)
            const [eh, em] = b.end_time.split(':').map(Number)
            const offsetStart = sh * 60 + sm - dayStartHour.value * 60
            const offsetEnd   = eh * 60 + em - dayStartHour.value * 60
            return {
                left:  Math.max(0, (offsetStart / totalMin) * 100),
                width: Math.max(0, ((offsetEnd - offsetStart) / totalMin) * 100),
                label: b.label,
            }
        })
}
```

- [ ] **Step 4: Render block overlays in the cell template**

In the template, inside the cell `<div>` (around line 184–224), after the hour-grid-lines `<div>` (after line 195) and before the now-indicator, add:

```html
<!-- Unavailability overlays -->
<template v-for="(overlay, oi) in getBlockOverlays(user.id, day.iso)" :key="'block-' + user.id + '-' + day.iso + '-' + oi">
    <div class="absolute top-0 bottom-0 bg-gray-200/70 dark:bg-slate-600/50 pointer-events-none z-[5]"
         :style="{ left: overlay.left + '%', width: overlay.width + '%' }">
        <span v-if="overlay.label"
              class="absolute top-0.5 left-1 text-[9px] text-gray-400 dark:text-gray-500 truncate select-none">
            {{ overlay.label }}
        </span>
    </div>
</template>
```

- [ ] **Step 5: Block onCellPointerDown**

In `onCellPointerDown` (line 602), after `if (!info) return` (line 606), add:

```javascript
const startMin = snapMinutes(info.minutes)
if (isBlockedAtTime(user.id, day.iso, startMin, startMin + slotMinutes.value)) return
```

Remove the duplicate `const startMin = snapMinutes(info.minutes)` that follows (rename the existing one to avoid conflict, or just replace the entire function body). The full updated function:

```javascript
function onCellPointerDown(e, user, day) {
    if (e.target.closest('[data-planner-event]')) return
    if (e.button !== 0) return
    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (!info) return
    const startMin = snapMinutes(info.minutes)
    if (isBlockedAtTime(user.id, day.iso, startMin, startMin + slotMinutes.value)) return
    selectRect.value = {
        userId:       user.id,
        dayIso:       day.iso,
        startMinutes: startMin,
        endMinutes:   startMin + slotMinutes.value,
        left:         (startMin / info.totalMin) * 100,
        width:        (slotMinutes.value / info.totalMin) * 100,
        cellRect:     info.rect,
        totalMin:     info.totalMin,
    }
    drag.value = { mode: 'select', user, day }
    e.preventDefault()
}
```

- [ ] **Step 6: Block onDragOver**

Replace the `onDragOver` function (lines 973–977) with:

```javascript
function onDragOver(e) {
    if (!(e.dataTransfer && e.dataTransfer.types?.includes('application/x-planner-payload'))) return
    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (info) {
        const startMin = dropStartMinutes(info, DROP_DURATION_MIN)
        if (isBlockedAtTime(info.userId, info.dayIso, startMin, startMin + DROP_DURATION_MIN)) {
            e.dataTransfer.dropEffect = 'none'
            dragGhost.value = null
            return
        }
    }
    e.dataTransfer.dropEffect = 'copy'
    updateExternalDropGhost(e.clientX, e.clientY)
}
```

- [ ] **Step 7: Block onExternalDrop**

Replace the `onExternalDrop` function (lines 1034–1048) with:

```javascript
function onExternalDrop(e, user, day) {
    dragGhost.value = null
    const raw = e.dataTransfer?.getData('application/x-planner-payload')
    if (!raw) return
    let payload
    try { payload = JSON.parse(raw) } catch { return }

    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (!info) return
    const duration = payload.duration_minutes || DROP_DURATION_MIN
    const startMin = dropStartMinutes(info, duration)
    if (isBlockedAtTime(user.id, day.iso, startMin, startMin + duration)) return
    const start = dateFromDayIsoAndMinutes(day.iso, startMin)
    const end   = new Date(start.getTime() + duration * 60000)
    createEventFromDrop({ start, end, userId: user.id, payload })
}
```

- [ ] **Step 8: Smoke test in browser**

1. Run `composer run dev` to start the dev stack.
2. Go to `/planner`.
3. Pick a plannable user and add a recurring block for today's day-of-week via `/users/{id}/edit`.
4. Reload the planner — a grey overlay should appear over the blocked period for that user.
5. Try dragging a service order onto the blocked period — the ghost should disappear and the drop should be rejected.
6. Try click-dragging a new event in the blocked period — nothing should happen.
7. Verify unblocked periods still work normally.

- [ ] **Step 9: Commit**

```bash
git add resources/js/Components/Planner/ResourcePlannerWidget.vue
git commit -m "feat(roster): planner fetches unavailabilities, renders overlays, blocks interaction"
```
