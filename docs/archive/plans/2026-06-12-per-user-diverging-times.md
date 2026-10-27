# Per-User Diverging Times Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow each executing user on a planner event to have their own start/end times that differ from the event's global times, with both the desktop grid and mobile timeline using those per-user times for positioning and display.

**Architecture:** Three nullable columns (`has_diverging_times`, `diverging_start`, `diverging_end`) are added to the existing `userables` pivot, consistent with how `breaktime` already lives there. The backend trait and API controller are extended to read/write these; the frontend modal gets a per-user checkbox + 4 selects; `PlannerEvent.vue` and `MobilePlannerView.vue` read the per-user data to override positioning/display.

**Tech Stack:** Laravel 12 (migrations, Eloquent traits, Form Requests, API controller), Vue 3 + Inertia, axios

---

## File Map

| File | Action | What changes |
|---|---|---|
| `database/migrations/2026_06_12_100001_add_diverging_times_to_userables_table.php` | Create | Migration adding 3 columns to `userables` |
| `app/Models/Traits/HasExecutingUsers.php` | Modify | Add `has_diverging_times`/`diverging_start`/`diverging_end` to `withPivot` and `syncExecutingUsers` |
| `app/Http/Requests/EventStoreRequest.php` | Modify | Add validation rules for `executing_user_diverging_times` |
| `app/Http/Requests/EventUpdateRequest.php` | Modify | Same as above |
| `app/Http/Controllers/EventApiController.php` | Modify | Pass `executing_user_diverging_times` through store/update; expose pivot fields in `withUserRoles` |
| `resources/js/Composables/usePlannerEvents.js` | Modify | Map `has_diverging_times`, `diverging_start`, `diverging_end` per executing user |
| `resources/js/Components/Planner/EventEditModal.vue` | Modify | Add `userDivergingTimes` state, per-user checkbox + 4 selects UI, include in save payload; pass executing_users back from `MobilePlannerView` |
| `resources/js/Components/Planner/ResourcePlannerWidget.vue` | Modify | Pass `executing_users` (with new pivot fields) through `openEdit` modalInitial |
| `resources/js/Components/Planner/MobilePlannerView.vue` | Modify | Pass `executing_users` in `handleEventTap`; `effectiveStartTime()`; `durationLabel()` uses diverging times |
| `resources/js/Components/Planner/PlannerEvent.vue` | Modify | `effectiveStartMin`/`effectiveEndMin` computed; time label uses diverging times when set |

---

### Task 1: Migration — add diverging time columns to `userables`

**Files:**
- Create: `database/migrations/2026_06_12_100001_add_diverging_times_to_userables_table.php`

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
        Schema::table('userables', function (Blueprint $table) {
            $table->boolean('has_diverging_times')->default(false)->after('breaktime');
            $table->time('diverging_start')->nullable()->after('has_diverging_times');
            $table->time('diverging_end')->nullable()->after('diverging_start');
        });
    }

    public function down(): void
    {
        Schema::table('userables', function (Blueprint $table) {
            $table->dropColumn(['has_diverging_times', 'diverging_start', 'diverging_end']);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_06_12_100001_add_diverging_times_to_userables_table` → `Migrated`

---

### Task 2: `HasExecutingUsers` trait — read and write diverging times

**Files:**
- Modify: `app/Models/Traits/HasExecutingUsers.php`

- [ ] **Step 1: Add the new pivot columns to the relationship and extend `syncExecutingUsers`**

Replace the entire file content:

```php
<?php

namespace App\Models\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;

trait HasExecutingUsers
{
    public function executingUsers(): MorphToMany
    {
        return $this
            ->morphToMany(User::class, 'userable')
            ->withPivot('id', 'type', 'breaktime', 'has_diverging_times', 'diverging_start', 'diverging_end')
            ->wherePivot('type', 'executing')
            ->withTimestamps();
    }

    public function hasExecutingUser(int $user_id): bool
    {
        return $this->executingUsers()->where('users.id', $user_id)->exists();
    }

    public function addExecutingUser(int $user_id): void
    {
        if (! $this->hasExecutingUser($user_id)) {
            $this->executingUsers()->attach($user_id, ['type' => 'executing']);
        }
    }

    public function syncSingleExecutingUser(int $user_id): void
    {
        $this->executingUsers()->detach();
        $this->executingUsers()->attach($user_id, ['type' => 'executing']);
    }

    public function syncExecutingUsers(array $user_ids, array $breaktimes = [], array $user_roles = [], array $diverging_times = []): void
    {
        $this->executingUsers()->detach();
        $attach = [];
        foreach (array_unique($user_ids) as $uid) {
            $dt = $diverging_times[$uid] ?? $diverging_times[(string) $uid] ?? [];
            $has = (bool) ($dt['has_diverging_times'] ?? false);
            $attach[$uid] = [
                'type'                => 'executing',
                'breaktime'           => (int) ($breaktimes[$uid] ?? 0),
                'has_diverging_times' => $has,
                'diverging_start'     => $has ? ($dt['diverging_start'] ?? null) : null,
                'diverging_end'       => $has ? ($dt['diverging_end'] ?? null) : null,
            ];
        }
        if ($attach) {
            $this->executingUsers()->attach($attach);
        }
        $this->syncExecutingUserRoles($user_roles);
    }

    protected function syncExecutingUserRoles(array $user_roles): void
    {
        if (empty($user_roles)) {
            return;
        }

        $userable_ids = DB::table('userables')
            ->where('userable_type', $this->getMorphClass())
            ->where('userable_id', $this->getKey())
            ->where('type', 'executing')
            ->pluck('id', 'user_id');

        $inserts = [];
        foreach ($userable_ids as $user_id => $userable_id) {
            $role_ids = $user_roles[$user_id] ?? $user_roles[(string) $user_id] ?? [];
            foreach (array_unique(array_map('intval', (array) $role_ids)) as $role_id) {
                if ($role_id > 0) {
                    $inserts[] = [
                        'userable_id'  => $userable_id,
                        'user_role_id' => $role_id,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ];
                }
            }
        }

        if ($inserts) {
            DB::table('user_role_userable')->insert($inserts);
        }
    }

    public function executingUser()
    {
        return $this->executingUsers()->first();
    }
}
```

---

### Task 3: Form Request validation — `EventStoreRequest` and `EventUpdateRequest`

**Files:**
- Modify: `app/Http/Requests/EventStoreRequest.php`
- Modify: `app/Http/Requests/EventUpdateRequest.php`

- [ ] **Step 1: Add rules to `EventStoreRequest`**

In `app/Http/Requests/EventStoreRequest.php`, inside `rules()`, add after the existing `executing_user_roles.*.*` line:

```php
'executing_user_diverging_times'                          => 'nullable|array',
'executing_user_diverging_times.*.has_diverging_times'   => 'nullable|boolean',
'executing_user_diverging_times.*.diverging_start'        => 'nullable|date_format:H:i',
'executing_user_diverging_times.*.diverging_end'          => 'nullable|date_format:H:i',
```

- [ ] **Step 2: Add rules to `EventUpdateRequest`**

In `app/Http/Requests/EventUpdateRequest.php`, inside `rules()`, add after the existing `executing_user_roles.*.*` line:

```php
'executing_user_diverging_times'                          => ['sometimes', 'nullable', 'array'],
'executing_user_diverging_times.*.has_diverging_times'   => ['nullable', 'boolean'],
'executing_user_diverging_times.*.diverging_start'        => ['nullable', 'date_format:H:i'],
'executing_user_diverging_times.*.diverging_end'          => ['nullable', 'date_format:H:i'],
```

---

### Task 4: `EventApiController` — pass through diverging times, expose in response

**Files:**
- Modify: `app/Http/Controllers/EventApiController.php`

- [ ] **Step 1: Pass `executing_user_diverging_times` in `store()`**

In `store()`, find the call to `$event->syncExecutingUsers(...)` (around line 107). Replace:

```php
$event->syncExecutingUsers($ids, $breaktimes, $user_roles);
```

with:

```php
$diverging_times = (array) ($request->input('executing_user_diverging_times', []));
$event->syncExecutingUsers($ids, $breaktimes, $user_roles, $diverging_times);
```

- [ ] **Step 2: Pass `executing_user_diverging_times` in `update()`**

In `update()`, find the call to `$event->syncExecutingUsers(...)` (around line 166). Replace:

```php
$event->syncExecutingUsers($ids, $breaktimes, $user_roles);
```

with:

```php
$diverging_times = (array) ($request->input('executing_user_diverging_times', []));
$event->syncExecutingUsers($ids, $breaktimes, $user_roles, $diverging_times);
```

- [ ] **Step 3: Expose diverging time pivot fields in `withUserRoles()`**

In `withUserRoles()`, after the line that sets `user_role_ids`, add the three new attributes:

```php
$user->pivot->setAttribute(
    'user_role_ids',
    $roles_by_userable->get($user->pivot->id, [])
);
// add these three lines:
$user->pivot->setAttribute('has_diverging_times', (bool) ($user->pivot->has_diverging_times ?? false));
$user->pivot->setAttribute('diverging_start', $user->pivot->diverging_start);
$user->pivot->setAttribute('diverging_end', $user->pivot->diverging_end);
```

---

### Task 5: `usePlannerEvents.js` — map diverging times per executing user

**Files:**
- Modify: `resources/js/Composables/usePlannerEvents.js`

- [ ] **Step 1: Extend the `executing_users` mapping in `mapEvent()`**

Replace the `executing_users` mapping:

```js
executing_users: (ev.executing_users || []).map((u) => ({
    id: u.id,
    name: u.name,
    breaktime: u.pivot?.breaktime ?? 0,
    user_role_ids: u.pivot?.user_role_ids ?? [],
})),
```

with:

```js
executing_users: (ev.executing_users || []).map((u) => ({
    id: u.id,
    name: u.name,
    breaktime: u.pivot?.breaktime ?? 0,
    user_role_ids: u.pivot?.user_role_ids ?? [],
    has_diverging_times: u.pivot?.has_diverging_times ?? false,
    diverging_start: u.pivot?.diverging_start ?? null,
    diverging_end: u.pivot?.diverging_end ?? null,
})),
```

---

### Task 6: `EventEditModal.vue` — per-user diverging times UI and payload

**Files:**
- Modify: `resources/js/Components/Planner/EventEditModal.vue`

- [ ] **Step 1: Add `userDivergingTimes` reactive state**

In `<script setup>`, after the `userRoleSelections` ref definition, add:

```js
const userDivergingTimes = ref(
    Object.fromEntries(
        (props.initial.executing_users || []).map(u => {
            const start = u.diverging_start ? u.diverging_start.slice(0, 5) : '08:00'
            const end   = u.diverging_end   ? u.diverging_end.slice(0, 5)   : '09:00'
            return [String(u.id), {
                has_diverging_times: u.has_diverging_times ?? false,
                startHour:   start.split(':')[0],
                startMinute: start.split(':')[1],
                endHour:     end.split(':')[0],
                endMinute:   end.split(':')[1],
            }]
        })
    )
)
```

- [ ] **Step 2: Initialise the entry for newly added users**

In `onUserSelected()`, after the `userRoleSelections` initialisation block:

```js
if (userDivergingTimes.value[String(userId)] === undefined) {
    userDivergingTimes.value[String(userId)] = {
        has_diverging_times: false,
        startHour: '08',
        startMinute: '00',
        endHour: '09',
        endMinute: '00',
    }
}
```

- [ ] **Step 3: Add diverging-times UI to each user row in the template**

In the template, inside the `v-for="user in selectedUsers"` user row, after the closing `</ComboBox>` for roles, add:

```html
<div class="flex items-center gap-2 mt-1.5 flex-wrap">
    <label :for="`diverging-${user.id}`"
        class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400 select-none cursor-pointer">
        <input :id="`diverging-${user.id}`" type="checkbox"
            v-model="userDivergingTimes[String(user.id)].has_diverging_times"
            class="rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer" />
        Afwijkende tijden
    </label>
    <template v-if="userDivergingTimes[String(user.id)].has_diverging_times">
        <div class="flex items-center gap-1 ml-2 flex-wrap">
            <span class="text-xs text-gray-500 dark:text-gray-400">Start</span>
            <select v-model="userDivergingTimes[String(user.id)].startHour" :class="timeSelectClass(false)">
                <option v-for="h in hours" :key="h" :value="h">{{ h }}</option>
            </select>
            <select v-model="userDivergingTimes[String(user.id)].startMinute" :class="timeSelectClass(false)">
                <option v-for="m in minutes" :key="m" :value="m">{{ m }}</option>
            </select>
            <span class="text-xs text-gray-500 dark:text-gray-400 ml-1">Einde</span>
            <select v-model="userDivergingTimes[String(user.id)].endHour" :class="timeSelectClass(false)">
                <option v-for="h in hours" :key="h" :value="h">{{ h }}</option>
            </select>
            <select v-model="userDivergingTimes[String(user.id)].endMinute" :class="timeSelectClass(false)">
                <option v-for="m in minutes" :key="m" :value="m">{{ m }}</option>
            </select>
        </div>
    </template>
</div>
```

- [ ] **Step 4: Include diverging times in the save payload**

In `save()`, inside the `payload` object literal, add after `executing_user_roles`:

```js
executing_user_diverging_times: Object.fromEntries(
    form.executing_user_ids.map(id => {
        const dt = userDivergingTimes.value[String(id)] ?? {}
        const has = dt.has_diverging_times ?? false
        return [id, {
            has_diverging_times: has,
            diverging_start: has ? `${dt.startHour}:${dt.startMinute}` : null,
            diverging_end:   has ? `${dt.endHour}:${dt.endMinute}`     : null,
        }]
    })
),
```

---

### Task 7: Pass `executing_users` through `modalInitial` in both planner widgets

**Files:**
- Modify: `resources/js/Components/Planner/ResourcePlannerWidget.vue`
- Modify: `resources/js/Components/Planner/MobilePlannerView.vue`

- [ ] **Step 1: `ResourcePlannerWidget` — `openEdit` already passes `executing_users`; verify it includes new fields**

In `ResourcePlannerWidget.vue`, find `openEdit(ev)` and confirm the `modalInitial` object contains:

```js
executing_users: [...(ev.executing_users || [])],
```

This line is already present. Because `mapEvent()` now includes `has_diverging_times`, `diverging_start`, `diverging_end` on each executing user, `ev.executing_users` already carries the new fields. No code change needed here.

- [ ] **Step 2: `MobilePlannerView` — add `executing_users` to `handleEventTap` modalInitial**

In `MobilePlannerView.vue`, find `handleEventTap(ev)`. The current `modalInitial.value` assignment is missing `executing_users`. Add it:

```js
modalInitial.value = {
    id: ev.id,
    event_type_id: ev.event_type_id,
    name: ev.name,
    description: ev.description,
    status: ev.status,
    start: ev.start,
    end: ev.end,
    eventable_type: ev.eventable_type,
    eventable_id: ev.eventable_id,
    customer_id: ev.customer_id,
    customer_name: ev.customer_name || null,
    executing_user_ids: [...ev.executing_user_ids],
    executing_users: [...(ev.executing_users || [])],   // ← add this line
}
```

---

### Task 8: `PlannerEvent.vue` — use diverging times for grid positioning and time label

**Files:**
- Modify: `resources/js/Components/Planner/PlannerEvent.vue`

- [ ] **Step 1: Add computed helpers for effective start/end minutes and labels**

In `<script setup>`, after the existing `userBreaktime` computed, add:

```js
const divergingUser = computed(() =>
    props.event.executing_users?.find(u => u.id === props.userId && u.has_diverging_times) ?? null
)

const effectiveStartMin = computed(() => {
    if (divergingUser.value?.diverging_start) {
        const [h, m] = divergingUser.value.diverging_start.slice(0, 5).split(':').map(Number)
        return h * 60 + m - props.dayStartHour * 60
    }
    return minutesFromDayStart(props.event.start)
})

const effectiveEndMin = computed(() => {
    if (divergingUser.value?.diverging_end) {
        const [h, m] = divergingUser.value.diverging_end.slice(0, 5).split(':').map(Number)
        return h * 60 + m - props.dayStartHour * 60
    }
    return minutesFromDayStart(props.event.end)
})

const effectiveStartLabel = computed(() =>
    divergingUser.value?.diverging_start
        ? divergingUser.value.diverging_start.slice(0, 5)
        : formatTime(props.event.start)
)

const effectiveEndLabel = computed(() =>
    divergingUser.value?.diverging_end
        ? divergingUser.value.diverging_end.slice(0, 5)
        : formatTime(props.event.end)
)
```

- [ ] **Step 2: Use `effectiveStartMin`/`effectiveEndMin` in the `style` computed**

In the `style` computed, replace:

```js
const startMin = Math.max(0, minutesFromDayStart(props.event.start))
const endMin = Math.min(totalMin.value, minutesFromDayStart(props.event.end))
```

with:

```js
const startMin = Math.max(0, effectiveStartMin.value)
const endMin = Math.min(totalMin.value, effectiveEndMin.value)
```

- [ ] **Step 3: Use `effectiveStartLabel`/`effectiveEndLabel` in the time display row**

In the template, find the clock row:

```html
<span class="truncate">{{ formatTime(event.start) }} – {{ formatTime(event.end) }}</span>
```

Replace with:

```html
<span class="truncate">{{ effectiveStartLabel }} – {{ effectiveEndLabel }}</span>
```

Also update the identical line inside the `#popper` slot:

```html
{{ formatTime(event.start) }} – {{ formatTime(event.end) }}
```

Replace with:

```html
{{ effectiveStartLabel }} – {{ effectiveEndLabel }}
```

---

### Task 9: `MobilePlannerView.vue` — use diverging times in time column and duration

**Files:**
- Modify: `resources/js/Components/Planner/MobilePlannerView.vue`

- [ ] **Step 1: Add `effectiveStartTime()` helper**

In `<script setup>`, after `durationLabel()`, add:

```js
function effectiveStartTime(ev) {
    const u = selectedUserId.value !== null
        ? ev.executing_users?.find(u => u.id === selectedUserId.value && u.has_diverging_times) ?? null
        : null
    if (u?.diverging_start) return u.diverging_start.slice(0, 5)
    return nlTime(ev.start)
}
```

- [ ] **Step 2: Use `effectiveStartTime()` in the template**

In the template, find the left-side time display inside the event loop:

```html
<div class="text-sm font-semibold tabular-nums leading-none">{{ nlTime(ev.start) }}
```

Replace with:

```html
<div class="text-sm font-semibold tabular-nums leading-none">{{ effectiveStartTime(ev) }}
```

- [ ] **Step 3: Update `durationLabel()` to use diverging times when set**

Replace the existing `durationLabel` function with:

```js
function durationLabel(ev) {
    const u = selectedUserId.value !== null
        ? ev.executing_users?.find(u => u.id === selectedUserId.value && u.has_diverging_times) ?? null
        : null
    const userBreaktime = selectedUserId.value !== null
        ? ev.executing_users?.find(u => u.id === selectedUserId.value)?.breaktime ?? 0
        : 0
    let mins
    if (u?.diverging_start && u?.diverging_end) {
        const [sh, sm] = u.diverging_start.slice(0, 5).split(':').map(Number)
        const [eh, em] = u.diverging_end.slice(0, 5).split(':').map(Number)
        mins = Math.round(Math.max(0, (eh * 60 + em) - (sh * 60 + sm) - userBreaktime))
    } else {
        mins = Math.round(Math.max(0, (ev.end - ev.start) / 60000 - userBreaktime))
    }
    const h = Math.floor(mins / 60)
    const m = mins % 60
    if (h > 0 && m > 0) return `${h}u ${m}m`
    if (h > 0) return `${h}u`
    return `${m}m`
}
```

---

## Self-Review Notes

- **Spec coverage:** All 8 spec sections covered across 9 tasks.
- **Placeholder scan:** No TBD/TODO; every step has complete code.
- **Type consistency:** `userDivergingTimes` shape (`has_diverging_times`, `startHour`, `startMinute`, `endHour`, `endMinute`) is consistent between Task 6 step 1 (init), step 2 (new user), step 3 (template), and step 4 (payload). Pivot field names (`has_diverging_times`, `diverging_start`, `diverging_end`) are consistent across Tasks 1–5 and 8–9.
- **`copy()` in EventApiController:** Intentionally left unchanged — copied events do not inherit diverging times (per spec section 7).
