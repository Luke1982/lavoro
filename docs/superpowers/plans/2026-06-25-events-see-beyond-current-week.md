# events.see_beyond_current_week Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a permission `events.see_beyond_current_week` that, when absent, locks the resource planner (desktop and mobile) to the current ISO week and prevents the API from returning events outside it.

**Architecture:** A migration seeds the permission; `EventPolicy` exposes a `seeBeyondCurrentWeek()` method; `EventReadRequest::prepareForValidation()` clamps the date range before the controller ever reads it; both planner components hide navigation buttons and guard navigation calls based on the same permission via Inertia's shared `auth.permissions`.

**Tech Stack:** Laravel 12, Inertia, Vue 3, dayjs (already used in the planner), Carbon.

## Global Constraints

- Permission name: `events.see_beyond_current_week` (plural `events`, consistent with collection-level read constraints in this codebase).
- "Current week" = ISO week, Monday–Sunday, consistent with the planners' existing use of `dayjs().startOf('isoWeek')`.
- No tests unless asked — project convention.
- No inline comments — project convention.
- Dutch labels for permissions and UI.
- Snake_case for all PHP variables.

---

### Task 1: Seed the permission

**Files:**
- Create: `database/migrations/2026_06_25_000002_seed_events_see_beyond_current_week_permission.php`

**Interfaces:**
- Produces: `Permission` row with `name = 'events.see_beyond_current_week'` in the database.

- [ ] **Step 1: Create the migration**

```php
<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'events.see_beyond_current_week', 'label' => 'Mag verder dan de huidige week kijken in de planning'],
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            if (! Permission::where('name', $permission['name'])->exists()) {
                Permission::create($permission);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::where('name', $permission['name'])->delete();
        }
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_06_25_000002_seed_events_see_beyond_current_week_permission` then `Migrated`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_25_000002_seed_events_see_beyond_current_week_permission.php
git commit -m "feat(Permissions): seed events.see_beyond_current_week permission"
```

---

### Task 2: Add policy method

**Files:**
- Modify: `app/Policies/EventPolicy.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `EventPolicy::seeBeyondCurrentWeek(User $user): bool` — called as `$user->can('seeBeyondCurrentWeek', Event::class)` in the Form Request.

- [ ] **Step 1: Add the method to `app/Policies/EventPolicy.php`**

Add this method after the `export()` method at line 65 (before the closing `}`):

```php
    public function seeBeyondCurrentWeek(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('events.see_beyond_current_week');
    }
```

- [ ] **Step 2: Commit**

```bash
git add app/Policies/EventPolicy.php
git commit -m "feat(Events): add seeBeyondCurrentWeek policy method"
```

---

### Task 3: Guard the API via EventReadRequest

**Files:**
- Modify: `app/Http/Requests/EventReadRequest.php`
- Modify: `app/Http/Controllers/EventApiController.php`

**Interfaces:**
- Consumes: `EventPolicy::seeBeyondCurrentWeek()` from Task 2.
- Produces: `EventApiController::index(EventReadRequest $request)` — the `start`/`end` query params are clamped to the current ISO week (Monday 00:00 UTC through the following Monday 00:00 UTC) before the controller reads them, when the user lacks the permission.

- [ ] **Step 1: Update `app/Http/Requests/EventReadRequest.php`**

Replace the entire file with:

```php
<?php

namespace App\Http\Requests;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Event index & show authorization.
 *
 * @mixin Request
 *
 * @method array validated()
 * @method mixed input(string $key = null, $default = null)
 * @method bool has(string $key)
 * @method bool filled(string $key)
 * @method mixed get(string $key, $default = null)
 * @method array all($keys = null)
 * @method mixed query(string $key = null, $default = null)
 */
class EventReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && ($user->isAdmin() || $user->hasPermission('event.read'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->user()?->can('seeBeyondCurrentWeek', Event::class)) {
            return;
        }

        $week_start = Carbon::now()->startOfWeek(Carbon::MONDAY)->startOfDay();

        $this->merge([
            'start' => $week_start->toIso8601ZuluString(),
            'end'   => $week_start->copy()->addDays(7)->toIso8601ZuluString(),
        ]);
    }

    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 2: Wire `EventReadRequest` into `EventApiController::index()`**

In `app/Http/Controllers/EventApiController.php`, add the import at the top (after the existing `use` statements):

```php
use App\Http\Requests\EventReadRequest;
```

Then change the `index()` signature at line 25 from:

```php
    public function index(Request $request)
```

to:

```php
    public function index(EventReadRequest $request)
```

The `use Illuminate\Http\Request;` import can stay — it is still used by other methods in the file.

- [ ] **Step 3: Verify the guard works**

Open the planner while logged in as a user without the permission and watch the network tab. The `GET /api/events` request should have its `start`/`end` params replaced by the current-week boundaries even if the frontend sends something else.

Alternatively, quickly test in Tinker:

```bash
php artisan tinker
```

```php
$user = \App\Models\User::first(); // a non-admin without the permission
$request = new \App\Http\Requests\EventReadRequest();
$request->merge(['start' => '2020-01-01T00:00:00Z', 'end' => '2020-12-31T00:00:00Z']);
$request->setUserResolver(fn () => $user);
$request->prepareForValidation(); // call protected via reflection or just trust the code
// The params should now be this week
```

- [ ] **Step 4: Commit**

```bash
git add app/Http/Requests/EventReadRequest.php app/Http/Controllers/EventApiController.php
git commit -m "feat(Events): clamp API date range to current week when permission is absent"
```

---

### Task 4: Lock desktop planner navigation

**Files:**
- Modify: `resources/js/Components/Planner/ResourcePlannerWidget.vue`

**Interfaces:**
- Consumes: `hasPermission('events.see_beyond_current_week')` from Inertia's shared `auth.permissions` (already available via the `hasPermission` helper imported at line 402).
- Produces: computed `showPrevButton`, `showNextButton`; guarded `loadStoredWeekStart()`, `shiftPeriod()`, `watch(weekStart)`.

- [ ] **Step 1: Add computed properties for button visibility**

In the `<script setup>` block, find the line:

```js
const selectedGroupIds = ref([])
```

(around line 535). Add the following computed properties directly after it:

```js
const current_iso_week_start = computed(() => dayjs().startOf('isoWeek'))

const showPrevButton = computed(() => {
    if (hasPermission('events.see_beyond_current_week')) return true
    if (plannerView.value !== 'day') return false
    return dayjs(weekStart.value).isAfter(current_iso_week_start.value, 'day')
})

const showNextButton = computed(() => {
    if (hasPermission('events.see_beyond_current_week')) return true
    if (plannerView.value !== 'day') return false
    return dayjs(weekStart.value).isBefore(current_iso_week_start.value.add(6, 'day'), 'day')
})
```

- [ ] **Step 2: Guard `loadStoredWeekStart()` to ignore stored week when permission is absent**

Find the function (around line 519):

```js
function loadStoredWeekStart() {
    const stored = localStorage.getItem(WEEK_STORAGE_KEY)
    if (stored) {
        const date = dayjs(stored, 'YYYY-MM-DD', true)
        if (date.isValid()) return date.toDate()
    }
    return startOfWeek(new Date())
}
```

Replace with:

```js
function loadStoredWeekStart() {
    if (!hasPermission('events.see_beyond_current_week')) {
        return startOfWeek(new Date())
    }
    const stored = localStorage.getItem(WEEK_STORAGE_KEY)
    if (stored) {
        const date = dayjs(stored, 'YYYY-MM-DD', true)
        if (date.isValid()) return date.toDate()
    }
    return startOfWeek(new Date())
}
```

- [ ] **Step 3: Guard `shiftPeriod()` against leaving the current week**

Find the function (around line 879):

```js
function shiftPeriod(direction) {
    const days = plannerView.value === 'day' ? 1 : 7
    weekStart.value = dayjs(weekStart.value).add(direction * days, 'day').toDate()
}
```

Replace with:

```js
function shiftPeriod(direction) {
    if (!hasPermission('events.see_beyond_current_week')) {
        if (plannerView.value !== 'day') return
        const next = dayjs(weekStart.value).add(direction, 'day')
        const week_start = dayjs().startOf('isoWeek')
        if (next.isBefore(week_start, 'day') || next.isAfter(week_start.add(6, 'day'), 'day')) return
    }
    const days = plannerView.value === 'day' ? 1 : 7
    weekStart.value = dayjs(weekStart.value).add(direction * days, 'day').toDate()
}
```

- [ ] **Step 4: Skip writing to localStorage when permission is absent**

Find the `watch(weekStart, ...)` block (around line 869):

```js
watch(weekStart, (val) => {
    localStorage.setItem(WEEK_STORAGE_KEY, dayjs(val).format('YYYY-MM-DD'))
    resetFingerprint()
    fetchEvents()
})
```

Replace with:

```js
watch(weekStart, (val) => {
    if (hasPermission('events.see_beyond_current_week')) {
        localStorage.setItem(WEEK_STORAGE_KEY, dayjs(val).format('YYYY-MM-DD'))
    }
    resetFingerprint()
    fetchEvents()
})
```

- [ ] **Step 5: Add `v-if` to the prev/next buttons in the template**

Find the two navigation buttons (around lines 12–20 of the template):

```html
            <button
                class="rounded-md border border-gray-300 dark:border-slate-700 p-1.5 hover:bg-gray-50 dark:hover:bg-slate-800"
                @click="shiftPeriod(-1)" :aria-label="plannerView === 'day' ? 'Vorige dag' : 'Vorige week'">
                <ChevronLeftIcon class="size-4" />
            </button>
            <button
                class="rounded-md border border-gray-300 dark:border-slate-700 p-1.5 hover:bg-gray-50 dark:hover:bg-slate-800"
                @click="shiftPeriod(1)" :aria-label="plannerView === 'day' ? 'Volgende dag' : 'Volgende week'">
                <ChevronRightIcon class="size-4" />
            </button>
```

Replace with:

```html
            <button v-if="showPrevButton"
                class="rounded-md border border-gray-300 dark:border-slate-700 p-1.5 hover:bg-gray-50 dark:hover:bg-slate-800"
                @click="shiftPeriod(-1)" :aria-label="plannerView === 'day' ? 'Vorige dag' : 'Vorige week'">
                <ChevronLeftIcon class="size-4" />
            </button>
            <button v-if="showNextButton"
                class="rounded-md border border-gray-300 dark:border-slate-700 p-1.5 hover:bg-gray-50 dark:hover:bg-slate-800"
                @click="shiftPeriod(1)" :aria-label="plannerView === 'day' ? 'Volgende dag' : 'Volgende week'">
                <ChevronRightIcon class="size-4" />
            </button>
```

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Planner/ResourcePlannerWidget.vue
git commit -m "feat(Planner): lock desktop planner to current week when permission is absent"
```

---

### Task 5: Lock mobile planner navigation

**Files:**
- Modify: `resources/js/Components/Planner/MobilePlannerView.vue`

**Interfaces:**
- Consumes: `hasPermission('events.see_beyond_current_week')` (already imported at line 289 of the mobile view).
- Produces: guarded `shiftWeek()`; hidden prev/next buttons; swipe is also blocked because it calls `shiftWeek()`.

- [ ] **Step 1: Guard `shiftWeek()` to no-op when permission is absent**

Find the function (around line 347):

```js
function shiftWeek(direction) {
    weekStart.value = dayjs(weekStart.value).add(direction * 7, 'day').toDate()
}
```

Replace with:

```js
function shiftWeek(direction) {
    if (!hasPermission('events.see_beyond_current_week')) return
    weekStart.value = dayjs(weekStart.value).add(direction * 7, 'day').toDate()
}
```

This also covers the swipe gesture, which calls `shiftWeek(±1)` via `useSwipe`.

- [ ] **Step 2: Hide prev/next buttons in the template**

Find the navigation buttons in the template (lines 6–14):

```html
            <div class="flex items-center justify-between px-4 py-3">
                <button class="p-2 rounded-md hover:bg-gray-100 dark:hover:bg-slate-800" aria-label="Vorige week"
                    @click="shiftWeek(-1)">
                    <ChevronLeftIcon class="size-5" />
                </button>
                <span class="font-semibold text-sm">{{ weekTitle }}</span>
                <button class="p-2 rounded-md hover:bg-gray-100 dark:hover:bg-slate-800" aria-label="Volgende week"
                    @click="shiftWeek(1)">
                    <ChevronRightIcon class="size-5" />
                </button>
            </div>
```

Replace with:

```html
            <div class="flex items-center justify-between px-4 py-3">
                <button v-if="hasPermission('events.see_beyond_current_week')"
                    class="p-2 rounded-md hover:bg-gray-100 dark:hover:bg-slate-800" aria-label="Vorige week"
                    @click="shiftWeek(-1)">
                    <ChevronLeftIcon class="size-5" />
                </button>
                <span v-else class="w-10 shrink-0" />
                <span class="font-semibold text-sm">{{ weekTitle }}</span>
                <button v-if="hasPermission('events.see_beyond_current_week')"
                    class="p-2 rounded-md hover:bg-gray-100 dark:hover:bg-slate-800" aria-label="Volgende week"
                    @click="shiftWeek(1)">
                    <ChevronRightIcon class="size-5" />
                </button>
                <span v-else class="w-10 shrink-0" />
            </div>
```

The `<span v-else class="w-10 shrink-0" />` spacers keep the week title centred when the buttons are hidden, matching the existing visual balance.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Planner/MobilePlannerView.vue
git commit -m "feat(Planner): lock mobile planner to current week when permission is absent"
```
