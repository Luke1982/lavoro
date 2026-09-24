# ServiceOrderTaskInstance UserRole Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a `ServiceOrderTaskInstance` be tagged with one or more `UserRole`s, and hide role-tagged task instances from non-admin executing users unless they hold a matching role on one of the events planning that service order.

**Architecture:** A new generic polymorphic pivot table `userroleables` (following the project's `-ables` convention) links `UserRole` to `ServiceOrderTaskInstance`. Visibility is computed server-side in `ServiceOrderController::show()` by comparing a task instance's required roles against the roles the viewing user holds on the events that plan the order (looked up via the existing `userables` → `user_role_userable` chain). The frontend gains a role picker (pill-style toggle buttons) in the task instance create/edit drawers and colored badges on task rows.

**Tech Stack:** Laravel 12 (migrations, Eloquent morphToMany), Vue 3 + Inertia, Tailwind CSS.

## Global Constraints

- PHP: snake_case for all variable names.
- No inline comments; prefer clear names and docblocks only when needed.
- Don't propose git commands or workflows beyond what's in each step.
- Don't write automated tests unless asked — this project's convention (per `CLAUDE.md`) is to verify manually via `php artisan tinker`, `php -l`, and the browser instead of PHPUnit. Every task below verifies this way instead of with a test suite.
- Validation belongs in Form Request `rules()` only; frontend only displays `form.errors`.
- Selecting/toggling in UI components: clicking a selected item deselects it — never add separate X / clear buttons.
- String concatenation always uses spaces around `.`.
- Reference spec: `docs/superpowers/specs/2026-07-06-taskinstance-userroles-design.md`.

---

### Task 1: `userroleables` pivot table migration

**Files:**
- Create: `database/migrations/2026_07_06_000001_create_userroleables_table.php`

**Interfaces:**
- Produces: a `userroleables` table with columns `id`, `user_role_id` (FK to `user_roles.id`, cascade delete), `userroleable_id`, `userroleable_type`, `created_at`, `updated_at`, and a unique constraint on `(user_role_id, userroleable_id, userroleable_type)`. Later tasks attach/sync rows here via Eloquent's `morphToMany`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('userroleables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_role_id')->constrained('user_roles')->cascadeOnDelete();
            $table->morphs('userroleable');
            $table->timestamps();
            $table->unique(['user_role_id', 'userroleable_id', 'userroleable_type'], 'userroleables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('userroleables');
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: output includes `2026_07_06_000001_create_userroleables_table ... DONE`

- [ ] **Step 3: Verify the table shape**

Run: `php artisan tinker --execute="print_r(Schema::getColumnListing('userroleables'));"`
Expected: array containing `id`, `user_role_id`, `userroleable_id`, `userroleable_type`, `created_at`, `updated_at`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_07_06_000001_create_userroleables_table.php
git commit -m "feat: add userroleables pivot table for tagging models with user roles"
```

---

### Task 2: `ServiceOrderTaskInstance::userRoles()` relation and `HasExecutingUsers::executingUserRoleIds()`

**Files:**
- Modify: `app/Models/ServiceOrderTaskInstance.php`
- Modify: `app/Models/Traits/HasExecutingUsers.php`

**Interfaces:**
- Consumes: `userroleables` table from Task 1; existing `userables` and `user_role_userable` tables (already in the database, used by `syncExecutingUserRoles()` in the same trait).
- Produces:
  - `ServiceOrderTaskInstance::userRoles(): MorphToMany` — standard Eloquent relation, supports `->attach()`, `->sync()`, `->get()`.
  - `HasExecutingUsers::executingUserRoleIds(int $user_id): array` — returns an array of integer `user_role_id`s the given user holds as an executing user on `$this` model instance (empty array if the user isn't an executing user on it, or holds no roles). Available on `Event`, `ServiceOrder`, and `ServiceJob` (all three `use HasExecutingUsers`). Task 4 calls this on `Event` instances.

- [ ] **Step 1: Add the `userRoles()` relation**

Edit `app/Models/ServiceOrderTaskInstance.php`, add this method alongside the other relations (e.g. after `product()`):

```php
    public function userRoles()
    {
        return $this->morphToMany(UserRole::class, 'userroleable')->withTimestamps();
    }
```

Add the import at the top of the file, alongside the existing `use` statements:

```php
use App\Models\UserRole;
```

- [ ] **Step 2: Add `executingUserRoleIds()` to the trait**

Edit `app/Models/Traits/HasExecutingUsers.php`, add this method after `syncExecutingUserRoles()`:

```php
    public function executingUserRoleIds(int $user_id): array
    {
        $userable_id = DB::table('userables')
            ->where('userable_type', $this->getMorphClass())
            ->where('userable_id', $this->getKey())
            ->where('type', 'executing')
            ->where('user_id', $user_id)
            ->value('id');

        if (! $userable_id) {
            return [];
        }

        return DB::table('user_role_userable')
            ->where('userable_id', $userable_id)
            ->pluck('user_role_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
```

- [ ] **Step 3: Verify with tinker**

Run: `php artisan tinker`

```php
$instance = \App\Models\ServiceOrderTaskInstance::first();
$role = \App\Models\UserRole::firstOrCreate(['name' => 'Tinker Test Role'], ['color' => '#ff0000']);
$instance->userRoles()->sync([$role->id]);
$instance->fresh()->userRoles->pluck('name');
// expect: Illuminate\Support\Collection {#... all: ["Tinker Test Role"]}
$instance->userRoles()->sync([]);
$role->delete();
```

Expected: the `pluck('name')` call returns a collection containing `"Tinker Test Role"`; no errors on either `sync()` call.

- [ ] **Step 4: Commit**

```bash
git add app/Models/ServiceOrderTaskInstance.php app/Models/Traits/HasExecutingUsers.php
git commit -m "feat: add userRoles relation to task instances and executingUserRoleIds helper"
```

---

### Task 3: Form Request validation and controller sync for `user_role_ids`

**Files:**
- Modify: `app/Http/Requests/ServiceOrderTaskInstanceStoreRequest.php`
- Modify: `app/Http/Requests/ServiceOrderTaskInstanceUpdateRequest.php`
- Modify: `app/Http/Controllers/ServiceOrderTaskInstanceController.php`

**Interfaces:**
- Consumes: `ServiceOrderTaskInstance::userRoles()` from Task 2.
- Produces: `store()` and `update()` on `ServiceOrderTaskInstanceController` accept an optional `user_role_ids` array in the request payload and sync it onto the instance's `userRoles()` relation. Task 6 (frontend) sends this field.

- [ ] **Step 1: Add validation rule to the store request**

Edit `app/Http/Requests/ServiceOrderTaskInstanceStoreRequest.php`, add to the `rules()` array (after `'is_complete'`):

```php
            'user_role_ids'   => ['sometimes', 'array'],
            'user_role_ids.*' => ['integer', 'exists:user_roles,id'],
```

- [ ] **Step 2: Add validation rule to the update request**

Edit `app/Http/Requests/ServiceOrderTaskInstanceUpdateRequest.php`, add to the `rules()` array (after `'description'`):

```php
            'user_role_ids'   => ['sometimes', 'array'],
            'user_role_ids.*' => ['integer', 'exists:user_roles,id'],
```

- [ ] **Step 3: Sync roles in `store()`**

Edit `app/Http/Controllers/ServiceOrderTaskInstanceController.php`. Replace:

```php
    public function store(ServiceOrderTaskInstanceStoreRequest $request)
    {
        ServiceOrderTaskInstance::create($request->validated());

        return redirect()->back()->with('success', 'Taak is toegevoegd');
    }
```

with:

```php
    public function store(ServiceOrderTaskInstanceStoreRequest $request)
    {
        $data = $request->validated();
        $user_role_ids = $data['user_role_ids'] ?? [];
        unset($data['user_role_ids']);

        $instance = ServiceOrderTaskInstance::create($data);
        $instance->userRoles()->sync($user_role_ids);

        return redirect()->back()->with('success', 'Taak is toegevoegd');
    }
```

- [ ] **Step 4: Sync roles in `update()`**

Replace:

```php
    public function update(ServiceOrderTaskInstanceUpdateRequest $request, ServiceOrderTaskInstance $serviceordertaskinstance)
    {
        $serviceordertaskinstance->update($request->validated());

        return redirect()->back()->with('success', 'Taak is bijgewerkt');
    }
```

with:

```php
    public function update(ServiceOrderTaskInstanceUpdateRequest $request, ServiceOrderTaskInstance $serviceordertaskinstance)
    {
        $data = $request->validated();

        if (array_key_exists('user_role_ids', $data)) {
            $serviceordertaskinstance->userRoles()->sync($data['user_role_ids']);
            unset($data['user_role_ids']);
        }

        $serviceordertaskinstance->update($data);

        return redirect()->back()->with('success', 'Taak is bijgewerkt');
    }
```

- [ ] **Step 5: Verify with PHP lint and tinker**

Run: `php -l app/Http/Controllers/ServiceOrderTaskInstanceController.php app/Http/Requests/ServiceOrderTaskInstanceStoreRequest.php app/Http/Requests/ServiceOrderTaskInstanceUpdateRequest.php`
Expected: `No syntax errors detected` for all three files.

Run: `php artisan tinker`

```php
$instance = \App\Models\ServiceOrderTaskInstance::first();
$role = \App\Models\UserRole::firstOrCreate(['name' => 'Tinker Test Role'], ['color' => '#ff0000']);
app(\App\Http\Controllers\ServiceOrderTaskInstanceController::class);
$instance->userRoles()->sync([$role->id]);
$instance->fresh()->userRoles->pluck('id')->toArray() === [$role->id];
// expect: true
$instance->userRoles()->sync([]);
$role->delete();
```

Expected: the boolean expression prints `true`. (Full HTTP round-trip through the controller is exercised in Task 7's browser check.)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/ServiceOrderTaskInstanceStoreRequest.php app/Http/Requests/ServiceOrderTaskInstanceUpdateRequest.php app/Http/Controllers/ServiceOrderTaskInstanceController.php
git commit -m "feat: accept and sync user_role_ids on task instance create/update"
```

---

### Task 4: Filter visible task instances by role in `ServiceOrderController::show()`

**Files:**
- Modify: `app/Http/Controllers/ServiceOrderController.php`

**Interfaces:**
- Consumes: `Event::executingUserRoleIds(int $user_id): array` (Task 2, inherited from `HasExecutingUsers`); `ServiceOrderTaskInstance::userRoles()` (Task 2); `User::isAdmin(): bool` (existing, `app/Models/User.php:116`).
- Produces: the Inertia response from `show()` carries a `userRoles` prop (all `UserRole` rows) and a `serviceOrder.task_instances` array already filtered for non-admin viewers. Task 5/6 (frontend) consume both.

- [ ] **Step 1: Add `UserRole` import**

Edit `app/Http/Controllers/ServiceOrderController.php`, add to the `use App\Models\...` block (alphabetically, after `use App\Models\Ticket;`):

```php
use App\Models\UserRole;
```

- [ ] **Step 2: Eager-load `taskInstances.userRoles`**

In the `with([...])` array inside `show()`, change:

```php
            'taskInstances.serviceOrderTask',
```

to:

```php
            'taskInstances.serviceOrderTask',
            'taskInstances.userRoles',
```

- [ ] **Step 3: Filter task instances for non-admin users**

In `show()`, immediately after the `$service_order = ServiceOrder::with([...])->findOrFail($id);` statement, add:

```php
        $user = Auth::user();

        if (! $user->isAdmin()) {
            $role_ids = $service_order->events
                ->flatMap(fn ($event) => $event->executingUserRoleIds($user->id))
                ->unique()
                ->values()
                ->all();

            $visible_instances = $service_order->taskInstances->filter(
                fn ($instance) => $instance->userRoles->isEmpty()
                    || $instance->userRoles->pluck('id')->intersect($role_ids)->isNotEmpty()
            )->values();

            $service_order->setRelation('taskInstances', $visible_instances);
        }
```

Note: `$service_order->events` must be loaded for this to work without extra queries — confirm the `with([...])` array already includes `'events.eventType'` and `'events.executingUsers:id,name'` (it does, per the existing code at lines 175-176); the `events` relation itself is therefore already eager-loaded.

- [ ] **Step 4: Pass `userRoles` to the Inertia response**

In the `return inertia('ServiceOrders/ShowPage', [...])` array, add (near `'customers'`):

```php
            'userRoles' => UserRole::orderBy('name')->get(['id', 'name', 'color']),
```

- [ ] **Step 5: Verify with PHP lint and tinker**

Run: `php -l app/Http/Controllers/ServiceOrderController.php`
Expected: `No syntax errors detected`

Run: `php artisan tinker`

```php
$order = \App\Models\ServiceOrder::with(['events', 'taskInstances.userRoles'])->first();
$order->events()->count();
$order->taskInstances->count();
```

Expected: no errors; both calls return integers (confirms the relations resolve without exceptions before hitting them through a real HTTP request in Task 7).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ServiceOrderController.php
git commit -m "feat: filter task instances by executing user role on service order show page"
```

---

### Task 5: Wire `userRoles` prop through `ShowPage.vue`

**Files:**
- Modify: `resources/js/Pages/ServiceOrders/ShowPage.vue`

**Interfaces:**
- Consumes: `userRoles` prop from Task 4's Inertia response — array of `{ id, name, color }`.
- Produces: `TaskInstancesWidget` receives a `user-roles` prop. Task 6 consumes it inside that component.

- [ ] **Step 1: Declare the prop**

Edit `resources/js/Pages/ServiceOrders/ShowPage.vue`. In the `defineProps({...})` block, add after `availableTasks: { type: Array, default: () => [] },`:

```js
    userRoles: { type: Array, default: () => [] },
```

- [ ] **Step 2: Pass it to `TaskInstancesWidget`**

Change:

```vue
                        <TaskInstancesWidget v-if="hasPermission('serviceordertaskinstance.read')"
                            :service-order-id="serviceOrder.id" :instances="serviceOrder.task_instances"
                            :available-tasks="availableTasks" :products="products" :is-closed="serviceOrder.is_closed"
                            class="my-4" />
```

to:

```vue
                        <TaskInstancesWidget v-if="hasPermission('serviceordertaskinstance.read')"
                            :service-order-id="serviceOrder.id" :instances="serviceOrder.task_instances"
                            :available-tasks="availableTasks" :products="products" :user-roles="userRoles"
                            :is-closed="serviceOrder.is_closed" class="my-4" />
```

- [ ] **Step 3: Verify the build**

Run: `npm run build`
Expected: build completes with no errors (warnings about chunk size, if any, are pre-existing and unrelated).

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/ServiceOrders/ShowPage.vue
git commit -m "feat: pass userRoles prop down to TaskInstancesWidget"
```

---

### Task 6: Role picker and badges in `TaskInstancesWidget.vue`

**Files:**
- Modify: `resources/js/Components/ServiceOrders/TaskInstancesWidget.vue`

**Interfaces:**
- Consumes: `userRoles` prop (Task 5) — array of `{ id, name, color }`; backend `user_roles` array on each task instance object (Task 4's eager-loaded, snake-cased relation — Eloquent serializes the `userRoles` relation as `user_roles` in JSON, matching the existing `service_order_task` pattern already used in this file).
- Produces: `addForm`/`editForm` submit a `user_role_ids` array (consumed by Task 3's controller); task rows render colored role badges.

- [ ] **Step 1: Declare the `userRoles` prop**

Edit `resources/js/Components/ServiceOrders/TaskInstancesWidget.vue`. Change:

```js
const props = defineProps({
    serviceOrderId: { type: Number, required: true },
    instances: { type: Array, default: () => [] },
    availableTasks: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    isClosed: { type: Boolean, default: false },
})
```

to:

```js
const props = defineProps({
    serviceOrderId: { type: Number, required: true },
    instances: { type: Array, default: () => [] },
    availableTasks: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    userRoles: { type: Array, default: () => [] },
    isClosed: { type: Boolean, default: false },
})
```

- [ ] **Step 2: Add role-id state and a shared toggle helper**

After the line `const newQuantity = ref(1)` (in the "Add drawer" section), add:

```js
const newUserRoleIds = ref([])
```

After the line `const editQuantity = ref(1)` (in the "Edit drawer" section), add:

```js
const editUserRoleIds = ref([])
```

After the `productOptions` computed (near the top, after `const productOptions = computed(...)`), add:

```js
function toggleRoleId(list, id) {
    const idx = list.value.indexOf(id)
    if (idx === -1) {
        list.value.push(id)
    } else {
        list.value.splice(idx, 1)
    }
}
```

- [ ] **Step 3: Include `user_role_ids` in the add form payload**

Change:

```js
const addForm = useForm({
    service_order_id: props.serviceOrderId,
    service_order_task_id: null,
    product_id: null,
    quantity: 1,
    title: '',
    description: '',
    is_complete: false,
})
```

to:

```js
const addForm = useForm({
    service_order_id: props.serviceOrderId,
    service_order_task_id: null,
    product_id: null,
    quantity: 1,
    title: '',
    description: '',
    is_complete: false,
    user_role_ids: [],
})
```

Change the `addInstance()` function from:

```js
function addInstance() {
    addForm.service_order_task_id = newTaskId.value
    addForm.product_id = newProductId.value
    addForm.quantity = newProductId.value ? newQuantity.value : 1
    addForm.title = newTitle.value.trim() || null
    addForm.description = newDescription.value.trim() || null

    addForm.post('/serviceordertaskinstances', {
        preserveScroll: true,
        onSuccess: () => {
            addDrawerOpen.value = false
            newTaskId.value = null
            newTitle.value = ''
            newDescription.value = ''
            newProductId.value = null
            newQuantity.value = 1
            addForm.reset()
            addForm.service_order_id = props.serviceOrderId
        },
    })
}
```

to:

```js
function addInstance() {
    addForm.service_order_task_id = newTaskId.value
    addForm.product_id = newProductId.value
    addForm.quantity = newProductId.value ? newQuantity.value : 1
    addForm.title = newTitle.value.trim() || null
    addForm.description = newDescription.value.trim() || null
    addForm.user_role_ids = newUserRoleIds.value

    addForm.post('/serviceordertaskinstances', {
        preserveScroll: true,
        onSuccess: () => {
            addDrawerOpen.value = false
            newTaskId.value = null
            newTitle.value = ''
            newDescription.value = ''
            newProductId.value = null
            newQuantity.value = 1
            newUserRoleIds.value = []
            addForm.reset()
            addForm.service_order_id = props.serviceOrderId
        },
    })
}
```

- [ ] **Step 4: Include `user_role_ids` in the edit form payload**

Change:

```js
const editForm = useForm({ title: '', description: '', product_id: null, quantity: 1 })

function openEditDrawer(instance) {
    editingInstance.value = instance
    editTitle.value = instance.title ?? ''
    editDescription.value = instance.description ?? instance.service_order_task?.description ?? ''
    editProductId.value = instance.product_id ?? null
    editQuantity.value = instance.quantity ?? 1
    editDrawerOpen.value = true
}

function saveEdit() {
    editForm.title = editTitle.value.trim() || null
    editForm.description = editDescription.value.trim() || null
    editForm.product_id = editProductId.value
    editForm.quantity = editProductId.value ? editQuantity.value : 1

    editForm.patch(`/serviceordertaskinstances/${editingInstance.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingInstance.value.title = editForm.title
            editingInstance.value.description = editForm.description
            editingInstance.value.product_id = editForm.product_id
            editingInstance.value.quantity = editForm.quantity
            editDrawerOpen.value = false
            editForm.reset()
        },
    })
}
```

to:

```js
const editForm = useForm({ title: '', description: '', product_id: null, quantity: 1, user_role_ids: [] })

function openEditDrawer(instance) {
    editingInstance.value = instance
    editTitle.value = instance.title ?? ''
    editDescription.value = instance.description ?? instance.service_order_task?.description ?? ''
    editProductId.value = instance.product_id ?? null
    editQuantity.value = instance.quantity ?? 1
    editUserRoleIds.value = (instance.user_roles ?? []).map(r => r.id)
    editDrawerOpen.value = true
}

function saveEdit() {
    editForm.title = editTitle.value.trim() || null
    editForm.description = editDescription.value.trim() || null
    editForm.product_id = editProductId.value
    editForm.quantity = editProductId.value ? editQuantity.value : 1
    editForm.user_role_ids = editUserRoleIds.value

    editForm.patch(`/serviceordertaskinstances/${editingInstance.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingInstance.value.title = editForm.title
            editingInstance.value.description = editForm.description
            editingInstance.value.product_id = editForm.product_id
            editingInstance.value.quantity = editForm.quantity
            editingInstance.value.user_roles = props.userRoles.filter(r => editForm.user_role_ids.includes(r.id))
            editDrawerOpen.value = false
            editForm.reset()
        },
    })
}
```

- [ ] **Step 5: Add the role toggle button group to the add drawer**

In the add drawer template, change:

```vue
                <div v-if="newProductId">
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Aantal</label>
                    <input type="number" v-model.number="newQuantity" min="1" max="999"
                        class="w-full rounded-md border-0 py-1.5 px-3 text-gray-900 dark:text-white ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm" />
                </div>
                <p v-if="addForm.errors.description || addForm.errors.title" class="text-xs text-red-600">
                    {{ addForm.errors.description || addForm.errors.title }}
                </p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="addDrawerOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button"
                        :disabled="addForm.processing || (!newTaskId && !newTitle.trim() && !newDescription.trim())"
                        @click="addInstance"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        Opslaan
                    </button>
                </div>
            </template>
        </DrawerComponent>
```

to:

```vue
                <div v-if="newProductId">
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Aantal</label>
                    <input type="number" v-model.number="newQuantity" min="1" max="999"
                        class="w-full rounded-md border-0 py-1.5 px-3 text-gray-900 dark:text-white ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm" />
                </div>
                <div v-if="userRoles.length">
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Rollen (optioneel)</label>
                    <div class="flex gap-2 flex-wrap">
                        <button v-for="role in userRoles" :key="role.id" type="button"
                            @click="toggleRoleId(newUserRoleIds, role.id)" :class="[
                                'px-3 py-1.5 rounded-md text-sm font-medium border transition-colors',
                                newUserRoleIds.includes(role.id)
                                    ? 'bg-lavoro-blue border-lavoro-blue text-white'
                                    : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                            ]">
                            {{ role.name }}
                        </button>
                    </div>
                </div>
                <p v-if="addForm.errors.description || addForm.errors.title" class="text-xs text-red-600">
                    {{ addForm.errors.description || addForm.errors.title }}
                </p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="addDrawerOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button"
                        :disabled="addForm.processing || (!newTaskId && !newTitle.trim() && !newDescription.trim())"
                        @click="addInstance"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        Opslaan
                    </button>
                </div>
            </template>
        </DrawerComponent>
```

- [ ] **Step 6: Add the same button group to the edit drawer**

In the edit drawer template, change:

```vue
                <div v-if="editProductId">
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Aantal</label>
                    <input type="number" v-model.number="editQuantity" min="1" max="999"
                        class="w-full rounded-md border-0 py-1.5 px-3 text-gray-900 dark:text-white ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm" />
                </div>
                <p v-if="editForm.errors.title || editForm.errors.description" class="text-xs text-red-600">
                    {{ editForm.errors.title || editForm.errors.description }}
                </p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="editDrawerOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button" :disabled="editForm.processing" @click="saveEdit"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        Opslaan
                    </button>
                </div>
            </template>
        </DrawerComponent>
```

to:

```vue
                <div v-if="editProductId">
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Aantal</label>
                    <input type="number" v-model.number="editQuantity" min="1" max="999"
                        class="w-full rounded-md border-0 py-1.5 px-3 text-gray-900 dark:text-white ring-1 ring-inset ring-gray-300 dark:ring-slate-500 focus:ring-2 focus:ring-inset focus:ring-indigo-600 dark:bg-slate-900 sm:text-sm" />
                </div>
                <div v-if="userRoles.length">
                    <label
                        class="block text-sm font-medium leading-6 text-gray-900 dark:text-gray-300 mb-2">Rollen (optioneel)</label>
                    <div class="flex gap-2 flex-wrap">
                        <button v-for="role in userRoles" :key="role.id" type="button"
                            @click="toggleRoleId(editUserRoleIds, role.id)" :class="[
                                'px-3 py-1.5 rounded-md text-sm font-medium border transition-colors',
                                editUserRoleIds.includes(role.id)
                                    ? 'bg-lavoro-blue border-lavoro-blue text-white'
                                    : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                            ]">
                            {{ role.name }}
                        </button>
                    </div>
                </div>
                <p v-if="editForm.errors.title || editForm.errors.description" class="text-xs text-red-600">
                    {{ editForm.errors.title || editForm.errors.description }}
                </p>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="editDrawerOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button" :disabled="editForm.processing" @click="saveEdit"
                        class="px-4 py-1.5 rounded-lavoro-sm text-sm bg-lavoro-blue text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity">
                        Opslaan
                    </button>
                </div>
            </template>
        </DrawerComponent>
```

- [ ] **Step 7: Add role badges to the task list rows**

Change:

```vue
                        <p v-if="instance.product" class="text-xs text-indigo-500 dark:text-indigo-400 mt-0.5">
                            {{ instance.quantity }}× {{ instance.product.brand.name }} {{ instance.product.model }}
                        </p>
                    </div>
```

to:

```vue
                        <p v-if="instance.product" class="text-xs text-indigo-500 dark:text-indigo-400 mt-0.5">
                            {{ instance.quantity }}× {{ instance.product.brand.name }} {{ instance.product.model }}
                        </p>
                        <div v-if="instance.user_roles?.length" class="flex flex-wrap gap-1 mt-1">
                            <span v-for="role in instance.user_roles" :key="role.id"
                                class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full text-white"
                                :style="{ backgroundColor: role.color }">
                                {{ role.name }}
                            </span>
                        </div>
                    </div>
```

- [ ] **Step 8: Reset `newUserRoleIds` when the add drawer is reopened**

In the button that opens the add drawer, no change needed to the handler itself (`@click="addDrawerOpen = true"`), but since `newUserRoleIds` is only reset inside `addInstance()`'s `onSuccess`, also reset it when the drawer is cancelled. Change:

```vue
                    <button type="button" @click="addDrawerOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button"
                        :disabled="addForm.processing || (!newTaskId && !newTitle.trim() && !newDescription.trim())"
                        @click="addInstance"
```

to:

```vue
                    <button type="button" @click="addDrawerOpen = false; newUserRoleIds = []"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">
                        Annuleren
                    </button>
                    <button type="button"
                        :disabled="addForm.processing || (!newTaskId && !newTitle.trim() && !newDescription.trim())"
                        @click="addInstance"
```

- [ ] **Step 9: Verify the build**

Run: `npm run build`
Expected: build completes successfully with no errors.

- [ ] **Step 10: Commit**

```bash
git add resources/js/Components/ServiceOrders/TaskInstancesWidget.vue
git commit -m "feat: add role picker and role badges to task instance drawers"
```

---

### Task 7: End-to-end verification

**Files:** none (verification only)

**Interfaces:**
- Consumes: everything from Tasks 1-6.

- [ ] **Step 1: Set up fixture data via tinker**

Run: `php artisan tinker`

```php
$role_a = \App\Models\UserRole::firstOrCreate(['name' => 'E2E Role A'], ['color' => '#2563ff']);
$role_b = \App\Models\UserRole::firstOrCreate(['name' => 'E2E Role B'], ['color' => '#dc2626']);

$order = \App\Models\ServiceOrder::with('events')->whereHas('events')->first();
$event = $order->events->first();
$user = \App\Models\User::where('is_admin', false)->first() ?? \App\Models\User::first();

$event->addExecutingUser($user->id);
$event->syncExecutingUserRoles([$user->id => [$role_a->id]]);

$restricted_to_a = $order->taskInstances()->create(['title' => 'E2E Restricted A']);
$restricted_to_a->userRoles()->sync([$role_a->id]);

$restricted_to_b = $order->taskInstances()->create(['title' => 'E2E Restricted B']);
$restricted_to_b->userRoles()->sync([$role_b->id]);

$unrestricted = $order->taskInstances()->create(['title' => 'E2E Unrestricted']);

echo "order_id={$order->id} user_id={$user->id}\n";
```

Note the printed `order_id` and `user_id` — the exact assertion in this task depends on your project's `User` model actually having an `is_admin` column/`isAdmin()` check; if `is_admin` isn't a raw column, adjust the `where` to however admin users are flagged in `app/Models/User.php:116`.

- [ ] **Step 2: Verify the filtering logic directly**

Still in tinker, using the `$order` and `$user` from Step 1:

```php
$role_ids = $order->events->flatMap(fn ($e) => $e->executingUserRoleIds($user->id))->unique()->values()->all();
$visible = $order->taskInstances()->with('userRoles')->get()->filter(
    fn ($i) => $i->userRoles->isEmpty() || $i->userRoles->pluck('id')->intersect($role_ids)->isNotEmpty()
)->pluck('title');
print_r($visible->all());
```

Expected: the array contains `"E2E Restricted A"` and `"E2E Unrestricted"`, but **not** `"E2E Restricted B"`.

- [ ] **Step 3: Clean up fixture data**

```php
$restricted_to_a->delete();
$restricted_to_b->delete();
$unrestricted->delete();
$role_a->delete();
$role_b->delete();
exit
```

- [ ] **Step 4: Browser check — role picker and badges**

Start the dev stack: `composer run dev`

In a browser, log in as an admin, navigate to a Service Order's show page, and:
1. Click "Taak toevoegen", confirm the "Rollen (optioneel)" pill button group appears listing all `UserRole`s, toggle one on and off (confirm it switches between the lavoro-blue filled state and the white/gray outline state), select one, save.
2. Confirm the new task row shows a colored badge with the role's name and color.
3. Click the edit (⋮) icon on that task, confirm the role picker opens pre-selected with the previously chosen role, change the selection, save, confirm the badge updates.
4. Confirm task instances with no role selected show no badge.

- [ ] **Step 5: Browser check — visibility filtering for a non-admin executing user**

Using the fixture pattern from Step 1 (recreate similarly, or use real data), log in as a non-admin user who is an executing user on an event linked to the service order, with a specific role assigned via the Planner's event edit modal. Navigate to that service order's show page and confirm:
1. Task instances with no role assigned are visible.
2. Task instances whose required role matches one of the user's roles on that event are visible.
3. Task instances requiring a role the user does not hold are not present in the list at all.

Report back which of these were confirmed, and any deviation from expected behavior.
