# Default Planner Minutes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce a `GeneralSetting` model seeded with `defaultplannerminutes = 120`, expose a permission-gated input in the planner toolbar to change that value, and use it for both drag-drop and single-click event creation.

**Architecture:** A new `general_settings` key/value table stores app-wide settings; a thin `GeneralSettingController` exposes a `PUT /api/settings/{key}` endpoint. The planner reads the value at page load via an Inertia prop and saves changes live via axios. Single-click vs click-drag is distinguished by a `dragged` flag on `selectRect`.

**Tech Stack:** Laravel 12 (Eloquent, Form Requests, Inertia), Vue 3 Composition API, axios.

---

## File Map

| Path | Action | Purpose |
|---|---|---|
| `database/migrations/2026_06_07_000001_create_general_settings_table.php` | Create | Table + seed `defaultplannerminutes` |
| `database/migrations/2026_06_07_000002_seed_default_planner_minutes_permission.php` | Create | Seed permission `settings.update_default_planner_minutes` |
| `app/Models/GeneralSetting.php` | Create | Eloquent model with `get()` / `set()` statics |
| `app/Http/Requests/GeneralSettingUpdateRequest.php` | Create | Authorize + validate setting update |
| `app/Http/Controllers/GeneralSettingController.php` | Create | `update()` action for `PUT /api/settings/{key}` |
| `routes/api.php` | Modify | Register `PUT settings/{key}` route |
| `app/Http/Controllers/PlannerController.php` | Modify | Pass `defaultPlannerMinutes` prop |
| `resources/js/Pages/Planner/IndexPage.vue` | Modify | Accept + forward `defaultPlannerMinutes` prop |
| `resources/js/Components/Planner/ResourcePlannerWidget.vue` | Modify | Toolbar input, use `plannerMinutes` everywhere, single-click detection |

---

### Task 1: Migration — `general_settings` table + seed

**Files:**
- Create: `database/migrations/2026_06_07_000001_create_general_settings_table.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('general_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->timestamps();
        });

        DB::table('general_settings')->insert([
            'key'        => 'defaultplannerminutes',
            'value'      => '120',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('general_settings');
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrated: 2026_06_07_000001_create_general_settings_table`

- [ ] **Step 3: Verify the seed row**

```bash
php artisan tinker --execute="echo App\Models\GeneralSetting::first()?->key ?? 'not found';"
```

Expected output: `defaultplannerminutes` (model doesn't exist yet — this will error; skip this step until Task 2 is done; the raw DB check below is sufficient)

```bash
php artisan tinker --execute="echo DB::table('general_settings')->where('key','defaultplannerminutes')->value('value');"
```

Expected: `120`

---

### Task 2: `GeneralSetting` model

**Files:**
- Create: `app/Models/GeneralSetting.php`

- [ ] **Step 1: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneralSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();
        return $row ? $row->value : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }
}
```

- [ ] **Step 2: Verify via tinker**

```bash
php artisan tinker --execute="echo App\Models\GeneralSetting::get('defaultplannerminutes', 0);"
```

Expected: `120`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_07_000001_create_general_settings_table.php app/Models/GeneralSetting.php
git commit -m "feat(settings): add GeneralSetting model and seed defaultplannerminutes=120"
```

---

### Task 3: Permission migration

**Files:**
- Create: `database/migrations/2026_06_07_000002_seed_default_planner_minutes_permission.php`

- [ ] **Step 1: Create the migration**

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
                'name'       => 'settings.update_default_planner_minutes',
                'label'      => 'Standaard planminuten instellen',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['name'], ['label', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', 'settings.update_default_planner_minutes')
            ->delete();
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrated: 2026_06_07_000002_seed_default_planner_minutes_permission`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_07_000002_seed_default_planner_minutes_permission.php
git commit -m "feat(settings): seed settings.update_default_planner_minutes permission"
```

---

### Task 4: Form Request + Controller + Route

**Files:**
- Create: `app/Http/Requests/GeneralSettingUpdateRequest.php`
- Create: `app/Http/Controllers/GeneralSettingController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Create the Form Request**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class GeneralSettingUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('settings.update_default_planner_minutes'));
    }

    public function rules(): array
    {
        return [
            'value' => ['required', 'integer', 'min:15', 'max:480'],
        ];
    }
}
```

- [ ] **Step 2: Create the Controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\GeneralSettingUpdateRequest;
use App\Models\GeneralSetting;

class GeneralSettingController extends Controller
{
    public function update(GeneralSettingUpdateRequest $request, string $key): \Illuminate\Http\JsonResponse
    {
        GeneralSetting::set($key, $request->validated()['value']);

        return response()->json(['key' => $key, 'value' => GeneralSetting::get($key)]);
    }
}
```

- [ ] **Step 3: Register the route in `routes/api.php`**

Add after the existing `use` statements at the top:
```php
use App\Http\Controllers\GeneralSettingController;
```

Add inside the `auth:sanctum` group:
```php
Route::put('settings/{key}', [GeneralSettingController::class, 'update']);
```

The group after editing should look like:
```php
Route::group(['middleware' => 'auth:sanctum'], function () {
    Route::resource('events', EventApiController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('events/{event}/send-confirmation', [EventApiController::class, 'sendConfirmation']);

    Route::get('projects', [ProjectApiController::class, 'index']);
    Route::get('projectmilestones', [ProjectApiController::class, 'milestones']);

    Route::get('google/integration/status', GoogleIntegrationStatusController::class)
        ->name('api.google.integration.status');

    Route::get('unavailabilities', [UnavailabilityApiController::class, 'index']);

    Route::put('settings/{key}', [GeneralSettingController::class, 'update']);
});
```

- [ ] **Step 4: Verify routes are registered**

```bash
php artisan route:list --path=api/settings
```

Expected: one PUT route `api/settings/{key}` pointing to `GeneralSettingController@update`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/GeneralSettingUpdateRequest.php app/Http/Controllers/GeneralSettingController.php routes/api.php
git commit -m "feat(settings): add PUT /api/settings/{key} endpoint"
```

---

### Task 5: Pass `defaultPlannerMinutes` from the controller

**Files:**
- Modify: `app/Http/Controllers/PlannerController.php`

- [ ] **Step 1: Add the import and prop at the end of the `inertia(...)` call**

At the top of `PlannerController.php`, add the import:
```php
use App\Models\GeneralSetting;
```

In the `inertia('Planner/IndexPage', [...])` array, add:
```php
'defaultPlannerMinutes' => (int) GeneralSetting::get('defaultplannerminutes', 120),
```

The complete `return` statement should now end with:
```php
            'plannableUsers' => User::where('plannable', true)
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'avatar' => $u->avatar,
                ]),
            'defaultPlannerMinutes' => (int) GeneralSetting::get('defaultplannerminutes', 120),
        ]);
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/PlannerController.php
git commit -m "feat(planner): pass defaultPlannerMinutes from GeneralSetting to Inertia"
```

---

### Task 6: Forward the prop in `IndexPage.vue`

**Files:**
- Modify: `resources/js/Pages/Planner/IndexPage.vue`

- [ ] **Step 1: Add the prop to `defineProps`**

In the `<script setup>` block, extend the existing `defineProps({...})` to add:
```js
defaultPlannerMinutes: { type: Number, default: 120 },
```

So `defineProps` becomes:
```js
const props = defineProps({
    eventTypes: { type: Array, required: true },
    allCustomers: { type: Array, required: true },
    customersUseAjax: { type: Boolean, default: false },
    allServiceOrders: { type: Array, required: true },
    eventStatusses: { type: Array, required: true },
    allUsers: { type: Array, required: true },
    plannableUsers: { type: Array, required: true },
    unplannedServiceOrders: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    defaultPlannerMinutes: { type: Number, default: 120 },
})
```

- [ ] **Step 2: Pass the prop to `ResourcePlannerWidget` in the template**

In the `<ResourcePlannerWidget ...>` element (desktop view), add `:default-planner-minutes="props.defaultPlannerMinutes"`:

```html
<ResourcePlannerWidget
    :event-types="eventTypes"
    :all-customers="allCustomers"
    :customers-use-ajax="customersUseAjax"
    :all-service-orders="allServiceOrders"
    :event-statusses="eventStatusses"
    :all-users="allUsers"
    :plannable-users="plannableUsers"
    :projects="projects"
    :default-planner-minutes="props.defaultPlannerMinutes"
    @service-order-planned="onServiceOrderPlanned"
    @service-order-unplanned="onServiceOrderUnplanned" />
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Planner/IndexPage.vue
git commit -m "feat(planner): forward defaultPlannerMinutes prop to ResourcePlannerWidget"
```

---

### Task 7: Update `ResourcePlannerWidget.vue`

This task makes all the frontend changes: accept the prop, add the toolbar input, use `plannerMinutes` everywhere, detect single-click vs drag.

**Files:**
- Modify: `resources/js/Components/Planner/ResourcePlannerWidget.vue`

- [ ] **Step 1: Add the `defaultPlannerMinutes` prop**

In `defineProps({...})` (around line 292), add after `rowHeight` and `eventPaddingY`:
```js
/** Default duration in minutes for new events created by drop or single click */
defaultPlannerMinutes: { type: Number, default: 120 },
```

- [ ] **Step 2: Replace the `DROP_DURATION_MIN` constant with a reactive ref**

Remove the line (around line 1151):
```js
const DROP_DURATION_MIN = 120
```

Replace it with:
```js
const plannerMinutes = ref(props.defaultPlannerMinutes)
```

- [ ] **Step 3: Replace every `DROP_DURATION_MIN` reference with `plannerMinutes.value`**

There are 5 occurrences. Update each:

Line ~1164 in `onDragOver`:
```js
const startMin = dropStartMinutes(info, plannerMinutes.value)
if (isBlockedAtTime(info.userId, info.dayIso, startMin, startMin + plannerMinutes.value)) {
```

Line ~1188 in `updateExternalDropGhost`:
```js
const startMin = dropStartMinutes(info, plannerMinutes.value)
const start = dateFromDayIsoAndMinutes(info.dayIso, startMin)
const end = new Date(start.getTime() + plannerMinutes.value * 60000)
```

Line ~1204 in `updateExternalDropGhost` (ghost width):
```js
const widthPx = (plannerMinutes.value / info.totalMin) * cellRect.width
```

Line ~1206 ghost title (change hardcoded "2 uur" to dynamic):
```js
title: `Nieuwe afspraak (${plannerMinutes.value} min)`,
```

Line ~1239 in `onExternalDrop`:
```js
const duration = payload.duration_minutes || plannerMinutes.value
```

- [ ] **Step 4: Add `dragged` flag to `selectRect` for single-click detection**

In `onCellPointerDown` (around line 763), in the `selectRect.value = { ... }` object, add `dragged: false`:
```js
selectRect.value = {
    userId: user.id,
    dayIso: day.iso,
    startMinutes: startMin,
    endMinutes: startMin + slotMinutes.value,
    left: (startMin / info.totalMin) * 100,
    width: (slotMinutes.value / info.totalMin) * 100,
    cellRect: info.rect,
    totalMin: info.totalMin,
    dragged: false,
}
```

- [ ] **Step 5: Set `dragged = true` on pointer move**

In `onWindowPointerMove`, in the `if (drag.value.mode === 'select')` block (around line 833), add `selectRect.value.dragged = true` right before the first assignment:
```js
if (drag.value.mode === 'select') {
    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (!info) return
    if (info.userId !== selectRect.value.userId || info.dayIso !== selectRect.value.dayIso) return
    selectRect.value.dragged = true
    const rawEnd = snapMinutes(info.minutes)
    const start = selectRect.value.startMinutes
    const end = Math.max(start + slotMinutes.value, rawEnd)
    selectRect.value.endMinutes = end
    selectRect.value.left = (Math.min(start, end) / info.totalMin) * 100
    selectRect.value.width = (Math.abs(end - start) / info.totalMin) * 100
    return
}
```

- [ ] **Step 6: Use `plannerMinutes` for single-click in `onWindowPointerUp`**

In `onWindowPointerUp`, in the `if (mode === 'select')` block (around line 923), override `endMinutes` when the selection was not dragged:
```js
if (mode === 'select') {
    const sel = selectRect.value
    selectRect.value = null
    drag.value = { eventId: null, mode: null }
    if (!sel) return
    const startMin = Math.min(sel.startMinutes, sel.endMinutes)
    const endMin = sel.dragged
        ? Math.max(sel.startMinutes, sel.endMinutes)
        : startMin + plannerMinutes.value
    const start = dateFromDayIsoAndMinutes(sel.dayIso, startMin)
    const end = dateFromDayIsoAndMinutes(sel.dayIso, endMin)
    openCreate({ start, end, userId: sel.userId })
    return
}
```

- [ ] **Step 7: Add the toolbar input (permission-gated)**

In the toolbar `<div class="ml-auto flex items-center gap-2">` (around line 23), add the input **before** the `<SelectMenuComponent>`:

```html
<div class="ml-auto flex items-center gap-2">
    <template v-if="hasPermission('settings.update_default_planner_minutes')">
        <label class="text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">Standaard min.</label>
        <input
            type="number"
            v-model.number="plannerMinutes"
            min="15"
            max="480"
            step="15"
            class="w-16 rounded-md border border-gray-300 dark:border-slate-700 dark:bg-slate-800 text-sm px-2 py-1 text-center"
            @blur="savePlannerMinutes" />
    </template>
    <SelectMenuComponent v-model="slotMinutes" :options="slotOptions" :icon="Squares2X2Icon">
        <template #sr-label>Slotgrootte</template>
    </SelectMenuComponent>
    ...
```

- [ ] **Step 8: Add `savePlannerMinutes` function**

Add this function in the `<script setup>` section, near the other async functions:

```js
async function savePlannerMinutes() {
    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.put('/api/settings/defaultplannerminutes', { value: plannerMinutes.value })
    } catch (e) {
        console.error('Failed to save planner minutes', e)
        page.props.flash.error = 'Kon standaard planminuten niet opslaan'
    }
}
```

- [ ] **Step 9: Fix the flash success message in `createEventFromDrop`**

Around line ~1272, update the hardcoded success message:
```js
page.props.flash.success = `Werkbon ingepland (${plannerMinutes.value} min)`
```

- [ ] **Step 10: Commit**

```bash
git add resources/js/Components/Planner/ResourcePlannerWidget.vue
git commit -m "feat(planner): use defaultPlannerMinutes for drop/single-click, add toolbar input"
```

---

### Task 8: Manual verification

- [ ] **Step 1: Build and start dev server**

```bash
npm run build 2>&1 | tail -5
composer run dev
```

- [ ] **Step 2: Verify toolbar input appears for privileged users**

Log in as an admin user. Open `/planner`. Confirm a "Standaard min." label and numeric input appear to the left of the slot-size selector.

- [ ] **Step 3: Verify drop uses the setting**

Change the input to `60`. Drag a service order from the sidebar onto the planner grid. Confirm the ghost shows a 1-hour span and the created event is 60 minutes.

- [ ] **Step 4: Verify single-click uses the setting**

Click (without dragging) on an empty cell. Confirm the event edit modal opens with a duration of 60 minutes (end = start + 60 min).

- [ ] **Step 5: Verify click-drag still uses the dragged range**

Click and drag across the grid. Confirm the selection rectangle reflects the dragged span, not the default minutes.

- [ ] **Step 6: Verify the input is hidden without the permission**

Log in as a user without `settings.update_default_planner_minutes`. Open `/planner`. Confirm the input is absent.

- [ ] **Step 7: Verify persistence**

Change the value, refresh the page. Confirm the input shows the saved value and drops/clicks still use it.
