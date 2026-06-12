# Project Location + Planner Location Resolution — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a freetext `location` field to Project, then update both planner views to show the best available location per event via a three-level priority chain (event → project → customer address).

**Architecture:** Backend: one migration + model + two form request changes. The existing `EventApiController` already eager-loads `serviceOrders.project` — we just add `location` to the select. Frontend: `mapEvent` in `usePlannerEvents.js` resolves the chain once; both planner components consume the resulting `event.location` field unchanged.

**Tech Stack:** Laravel 12, Inertia + Vue 3, `useForm` from `@inertiajs/vue3`, `EditableTextField` component.

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `database/migrations/YYYY_MM_DD_add_location_to_projects_table.php` | Create | Add nullable `location` string column |
| `app/Models/Project.php` | Modify | Add `location` to `$fillable` |
| `app/Http/Requests/ProjectStoreRequest.php` | Modify | Allow `location` on create |
| `app/Http/Requests/ProjectUpdateRequest.php` | Modify | Allow `location` on update |
| `app/Http/Controllers/EventApiController.php` | Modify | Include `location` in project eager-load select |
| `resources/js/Composables/usePlannerEvents.js` | Modify | Priority-chain resolution in `mapEvent` |
| `resources/js/Pages/Projects/ShowPage.vue` | Modify | Add `location` inline field |
| `resources/js/Components/Planner/MobilePlannerView.vue` | Modify | Replace `resolveAddress` with `ev.location` |

---

## Task 1: Migration — add `location` to projects table

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_location_to_projects_table.php`

- [ ] **Step 1: Generate the migration**

```bash
php artisan make:migration add_location_to_projects_table --table=projects
```

- [ ] **Step 2: Fill in the migration body**

Open the generated file and replace the `up`/`down` methods:

```php
public function up(): void
{
    Schema::table('projects', function (Blueprint $table) {
        $table->string('location')->nullable()->after('end_date');
    });
}

public function down(): void
{
    Schema::table('projects', function (Blueprint $table) {
        $table->dropColumn('location');
    });
}
```

- [ ] **Step 3: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrating: YYYY_MM_DD_HHMMSS_add_location_to_projects_table` → `Migrated`.

---

## Task 2: Update Project model

**Files:**
- Modify: `app/Models/Project.php`

- [ ] **Step 1: Add `location` to `$fillable`**

Current `$fillable` in [app/Models/Project.php](app/Models/Project.php):
```php
protected $fillable = [
    'title',
    'description',
    'start_date',
    'end_date',
    'customer_id',
    'project_manager_id',
    'status',
];
```

Replace with:
```php
protected $fillable = [
    'title',
    'description',
    'location',
    'start_date',
    'end_date',
    'customer_id',
    'project_manager_id',
    'status',
];
```

---

## Task 3: Update form requests

**Files:**
- Modify: `app/Http/Requests/ProjectStoreRequest.php`
- Modify: `app/Http/Requests/ProjectUpdateRequest.php`

- [ ] **Step 1: Add `location` to `ProjectStoreRequest::rules()`**

In [app/Http/Requests/ProjectStoreRequest.php](app/Http/Requests/ProjectStoreRequest.php), add inside `rules()` after `'description'`:

```php
'location'           => ['nullable', 'string', 'max:255'],
```

- [ ] **Step 2: Add `location` to `ProjectUpdateRequest::rules()`**

In [app/Http/Requests/ProjectUpdateRequest.php](app/Http/Requests/ProjectUpdateRequest.php), add inside `rules()` after `'description'`:

```php
'location'           => ['sometimes', 'nullable', 'string', 'max:255'],
```

---

## Task 4: Expose project location in EventApiController

**Files:**
- Modify: `app/Http/Controllers/EventApiController.php` (line ~57)

The `index` method eager-loads projects like this:
```php
'serviceOrders.project:id,title',
```

- [ ] **Step 1: Add `location` to the project select**

Change that line to:
```php
'serviceOrders.project:id,title,location',
```

This ensures `project.location` is available in the API response that feeds `usePlannerEvents.js`.

---

## Task 5: Location resolution in usePlannerEvents.js

**Files:**
- Modify: `resources/js/Composables/usePlannerEvents.js`

Currently `mapEvent` has:
```js
const customer_id = ev.service_orders?.[0]?.customer_id ?? null;
const customer = ev.service_orders?.[0]?.customer ?? null;
// ... later ...
location: ev.location || null,
```

- [ ] **Step 1: Replace the static location line with the priority-chain resolver**

Replace:
```js
location: ev.location || null,
```

With:
```js
location: ev.location
    || ev.service_orders?.[0]?.project?.location
    || [customer?.address, customer?.city].filter(Boolean).join(', ')
    || null,
```

`customer` is already defined two lines above in the same function — no new variable needed.

---

## Task 6: Project ShowPage — add location field

**Files:**
- Modify: `resources/js/Pages/Projects/ShowPage.vue`

The page uses `useForm` + per-field `watch` that calls `patchField`. The grid repeats a label col + field col pattern.

- [ ] **Step 1: Add `location` to the form initialisation**

Around line 386–394, the `useForm` call looks like:
```js
const form = useForm({
    title: props.project.title,
    description: props.project.description,
    start_date: props.project.start_date?.substring(0, 10) ?? null,
    end_date: props.project.end_date?.substring(0, 10) ?? null,
    customer_id: props.project.customer_id,
    project_manager_id: props.project.project_manager_id,
    status: initialStatus?.id ?? props.project.status,
})
```

Add `location` to it:
```js
const form = useForm({
    title: props.project.title,
    description: props.project.description,
    location: props.project.location ?? null,
    start_date: props.project.start_date?.substring(0, 10) ?? null,
    end_date: props.project.end_date?.substring(0, 10) ?? null,
    customer_id: props.project.customer_id,
    project_manager_id: props.project.project_manager_id,
    status: initialStatus?.id ?? props.project.status,
})
```

- [ ] **Step 2: Add `location` to the auto-watch array**

Around line 404, the watched fields array is:
```js
['title', 'description', 'start_date', 'end_date', 'customer_id', 'project_manager_id'].forEach(field =>
    watch(() => form[field], val => patchField(field, val))
)
```

Add `'location'`:
```js
['title', 'description', 'location', 'start_date', 'end_date', 'customer_id', 'project_manager_id'].forEach(field =>
    watch(() => form[field], val => patchField(field, val))
)
```

- [ ] **Step 3: Add the template row after the Omschrijving row**

After the description row (around line 24–25 in the template), insert:

```html
<div class="col-span-12 md:col-span-2 mt-2 sm:mt-0 text-slate-400">
    <span class="text-xs font-bold">Locatie</span>
</div>
<div class="col-span-12 md:col-span-10">
    <EditableTextField v-model="form.location" class="w-full" />
</div>
```

---

## Task 7: Mobile planner — replace resolveAddress with ev.location

**Files:**
- Modify: `resources/js/Components/Planner/MobilePlannerView.vue`

The mobile planner has a `resolveAddress(ev)` function (around line 433–441) and uses it in the template (around line 147–150).

- [ ] **Step 1: Remove the resolveAddress function**

Delete this entire function:
```js
function resolveAddress(ev) {
    if (!ev.eventable_id) return null
    const so = props.allServiceOrders.find(s => s.id === ev.eventable_id)
    if (!so) return null
    const customer = so.customer ?? null
    if (!customer) return null
    const parts = [customer.address, customer.city].filter(Boolean)
    return parts.length ? parts.join(', ') : null
}
```

- [ ] **Step 2: Update the template Address block**

Replace:
```html
<!-- Address -->
<div v-if="resolveAddress(ev)"
    class="text-xs text-gray-500 dark:text-slate-400 leading-snug">
    {{ resolveAddress(ev) }}
</div>
```

With:
```html
<!-- Address -->
<div v-if="ev.location"
    class="text-xs text-gray-500 dark:text-slate-400 leading-snug">
    {{ ev.location }}
</div>
```

---

## Self-review notes

- All 8 files in the file map are covered by tasks 1–7.
- `location` field value flows: DB → model → request validation → Inertia prop → `form.location` → `patchField` → `ProjectUpdateRequest` → `project.update()` → DB round-trip.
- Planner flow: DB → `serviceOrders.project:id,title,location` eager-load → API JSON → `mapEvent` priority chain → `event.location` → both planner views.
- No `ProjectStoreUpdateRequest` is used by `ProjectController` (it uses `ProjectStoreRequest` and `ProjectUpdateRequest` separately) — no change needed there.
- `customer` variable in `mapEvent` is already defined as `ev.service_orders?.[0]?.customer ?? null` at the top of the function — the priority chain uses it directly, no duplication.
