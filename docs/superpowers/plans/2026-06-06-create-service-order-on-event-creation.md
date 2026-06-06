# Create Service Order On Event Creation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Maak een nieuwe werkbon aan" checkbox to the new-event modal that atomically creates a bare `ServiceOrder` (customer only, first stage auto-assigned) and links it to the new event in a single POST.

**Architecture:** The frontend sends `create_service_order: true` + `customer_id` in the existing `POST /api/events` payload. `EventStoreRequest` validates the new fields. `EventApiController::store()` creates the `ServiceOrder` first (letting the model boot hook assign the first stage), overwrites `eventable_id` in `$data`, then proceeds with the unchanged event-creation flow.

**Tech Stack:** Laravel 12 (Form Requests, Eloquent), Vue 3 + `useForm` (Inertia), axios.

---

## File map

| File | Change |
|---|---|
| `app/Http/Requests/EventStoreRequest.php` | Add two validation rules |
| `app/Http/Controllers/EventApiController.php` | Insert SO creation block in `store()` |
| `resources/js/Components/Planner/EventEditModal.vue` | Add form field, checkbox UI, payload changes |

---

### Task 1: Add validation rules to EventStoreRequest

**Files:**
- Modify: `app/Http/Requests/EventStoreRequest.php`

- [ ] **Step 1: Add the two new rules to `rules()`**

Open `app/Http/Requests/EventStoreRequest.php`. The `rules()` array currently ends at `executing_user_ids.*`. Add two entries immediately before `executing_user_ids`:

```php
public function rules(): array
{
    return [
        'name'                 => 'nullable|string|max:255',
        'description'          => 'nullable|string',
        'event_type_id'        => 'required|exists:event_types,id',
        'status'               => 'required|in:Gepland,Gaande,Afgerond,Geannuleerd',
        'start'                => 'required|date_format:Y-m-d H:i',
        'end'                  => 'required|date_format:Y-m-d H:i|after_or_equal:start',
        'location'             => 'nullable|string|max:255',
        'eventable_type'       => 'nullable|string|in:\\App\\Models\\ServiceOrder',
        'eventable_id'         => 'nullable|exists:service_orders,id',
        'create_service_order' => 'nullable|boolean',
        'customer_id'          => 'required_if:create_service_order,true|nullable|exists:customers,id',
        'executing_user_ids'   => 'required|array|min:1',
        'executing_user_ids.*' => 'exists:users,id',
    ];
}
```

- [ ] **Step 2: Verify PHP syntax**

```bash
php artisan route:list --path=api/events
```

Expected: no errors, the route listing shows `/api/events`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/EventStoreRequest.php
git commit -m "feat(events): add create_service_order + customer_id validation rules"
```

---

### Task 2: Create the ServiceOrder atomically in EventApiController::store()

**Files:**
- Modify: `app/Http/Controllers/EventApiController.php`

- [ ] **Step 1: Replace the `store()` method body**

Open `app/Http/Controllers/EventApiController.php`. Replace the entire `store()` method with the following. The only new logic is the `$eventable_id` capture and the `if ($request->boolean(...))` block; the rest is identical to the original.

```php
public function store(EventStoreRequest $request)
{
    $data = $request->validated();
    unset($data['executing_user_ids']);

    $eventable_id = $request->eventable_id;

    if ($request->boolean('create_service_order')) {
        $new_order = ServiceOrder::create(['customer_id' => $data['customer_id']]);
        $data['eventable_type'] = '\\App\\Models\\ServiceOrder';
        $data['eventable_id']   = $new_order->id;
        $eventable_id           = $new_order->id;
    }

    unset($data['create_service_order'], $data['customer_id']);

    $event = Event::create($data);

    $class = $request->eventable_type;
    $model = $class::findOrFail($eventable_id);
    $model->events()->attach($event->id);
    if ($model instanceof ServiceOrder) {
        $model->advanceToPlannedStage();
    }

    $executing_user_ids = $request['executing_user_ids'] ?? [];
    if (is_array($executing_user_ids) && count($executing_user_ids) > 0) {
        $event->syncExecutingUsers(array_map('intval', $executing_user_ids));
        $model->syncExecutingUsers(array_map('intval', $executing_user_ids));
        $model->serviceJobs()->each(function ($job) use ($executing_user_ids) {
            $job->syncExecutingUsers(array_map('intval', $executing_user_ids));
        });
    }

    return response()->json($event->load(['eventType', 'serviceOrders', 'executingUsers:id,name']), 201);
}
```

- [ ] **Step 2: Verify PHP syntax**

```bash
php artisan route:list --path=api/events
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/EventApiController.php
git commit -m "feat(events): create bare ServiceOrder atomically when create_service_order flag is set"
```

---

### Task 3: Add checkbox UI and wire up the form in EventEditModal.vue

**Files:**
- Modify: `resources/js/Components/Planner/EventEditModal.vue`

#### Step 1 — Add `create_service_order` to the form

- [ ] In the `useForm({...})` call (around line 264), add `create_service_order: false` after the `customer_id` line:

```js
const form = useForm({
    id: props.initial.id || '',
    event_type_id: props.initial.event_type_id || props.eventTypes[0]?.id || '',
    name: props.initial.name || '',
    description: props.initial.description || '',
    status: props.initial.status || props.eventStatusses[0]?.name || 'Gepland',
    start_date: formatLocalDateAsISO(props.initial.start),
    end_date: formatLocalDateAsISO(props.initial.end),
    start_time: nlTime(props.initial.start),
    end_time: nlTime(props.initial.end),
    eventable_type: props.initial.eventable_type || '\\App\\Models\\ServiceOrder',
    eventable_id: props.initial.eventable_id || '',
    customer_id: props.initial.customer_id || null,
    location: props.initial.location || '',
    executing_user_ids: props.initial.executing_user_ids || [],
    create_service_order: false,
})
```

#### Step 2 — Reset `create_service_order` when customer is cleared

- [ ] Find the `watch(selectedCustomer, ...)` block (around line 318) and add the reset at the top of the callback:

```js
watch(selectedCustomer, (val, oldVal) => {
    if (val === oldVal) return
    if (!val) {
        form.create_service_order = false
    }
    if (!internalServiceOrders.value.some(o => o.id === form.eventable_id)) {
        form.eventable_id = internalServiceOrders.value[0]?.id || ''
    }
})
```

#### Step 3 — Update the Werkbon section in the template

- [ ] Find the Werkbon `<div>` inside the `<!-- Werkbon / Locatie -->` grid (around line 119). Replace the inner content of the Werkbon column (keep the grid wrapper and the Locatie column unchanged) with:

```html
<!-- Werkbon / Locatie -->
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <div class="flex items-center gap-2 mb-2">
            <div class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                <DocumentIcon class="h-3.5 w-3.5 text-lavoro-blue" />
            </div>
            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                Werkbon
                <span class="font-normal text-gray-400 dark:text-gray-500">(optioneel)</span>
            </span>
        </div>
        <ComboBox v-if="!form.create_service_order" v-model="form.eventable_id"
            :options="internalServiceOrders" class="w-full"
            :initial-id="form.eventable_id"
            placeholder="Zoek werkbon..."
            :hasError="Boolean(form.errors.eventable_id)"
            :errorMessage="form.errors.eventable_id" />
        <p v-else class="text-sm italic text-gray-500 dark:text-gray-400 py-2">
            Er wordt een nieuwe werkbon aangemaakt voor de geselecteerde klant.
        </p>
        <label v-if="!editingExisting"
            class="flex items-center gap-2 mt-2 select-none"
            :class="selectedCustomer ? 'cursor-pointer' : 'opacity-40 cursor-not-allowed'">
            <input
                type="checkbox"
                v-model="form.create_service_order"
                :disabled="!selectedCustomer"
                class="rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer disabled:cursor-not-allowed" />
            <span class="text-sm text-gray-600 dark:text-gray-400">Maak een nieuwe werkbon aan</span>
        </label>
    </div>
    <div>
        <div class="flex items-center gap-2 mb-2">
            <div class="w-6 h-6 bg-lavoro-lightblue dark:bg-blue-900/40 rounded-md flex items-center justify-center">
                <BuildingOffice2Icon class="h-3.5 w-3.5 text-lavoro-blue" />
            </div>
            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Locatie</span>
        </div>
        <TextInput v-model="form.location" label="" type="text" class="w-full"
            placeholder="Zoek locatie..." />
    </div>
</div>
```

#### Step 4 — Update the `save()` payload

- [ ] Find the `save()` function (around line 344). Update the `payload` construction to:
  - Always send `customer_id: selectedCustomer.value` (reactive ref, not the potentially stale `form.customer_id`)
  - Send `eventable_id: null` when `create_service_order` is true (empty string would fail `nullable|exists` on the API middleware group which does not run `ConvertEmptyStringsToNull`)

```js
async function save() {
    await axios.get('sanctum/csrf-cookie')
    const payload = {
        ...form,
        start: localToUtcDatetime(form.start_date, form.start_time).slice(0, 16),
        end: localToUtcDatetime(form.end_date, form.end_time).slice(0, 16),
        executing_user_ids: form.executing_user_ids,
        customer_id: selectedCustomer.value,
        eventable_id: form.create_service_order ? null : (form.eventable_id || null),
    }
    try {
        if (props.editingExisting && form.id) {
            const authId = page.props.auth?.user?.id ?? null
            const canUpdate = hasPermission('event.update_others') ||
                (hasPermission('event.update') && form.executing_user_ids.includes(authId))
            if (!canUpdate) return
            const r = await axios.put(`/api/events/${form.id}`, payload)
            if (r.status !== 200) throw new Error('bad')
            page.props.flash.success = 'Afspraak succesvol bijgewerkt'
        } else {
            if (!hasPermission('event.create')) return
            const r = await axios.post('/api/events', payload)
            if (r.status !== 201) throw new Error('bad')
            page.props.flash.success = 'Afspraak succesvol opgeslagen'
        }
        emit('saved')
    } catch (e) {
        if (e.response?.status === 422) {
            const errs = e.response.data?.errors || {}
            form.clearErrors()
            Object.keys(errs).forEach(k => form.setError(k, Array.isArray(errs[k]) ? errs[k][0] : String(errs[k])))
        }
        page.props.flash.error = e.response?.data?.message || 'Kon afspraak niet opslaan'
    }
}
```

#### Step 5 — Smoke test in the browser

- [ ] Run the dev stack:

```bash
composer run dev
```

Open the planner. Click "Nieuwe afspraak":
1. With no customer selected — confirm the checkbox is absent/disabled and the Werkbon ComboBox shows normally.
2. Select a customer that has existing service orders — confirm the ComboBox shows, the checkbox appears unchecked and enabled.
3. Check the checkbox — confirm the ComboBox disappears and the italic notice appears.
4. Fill in all required fields (date/time, type, status, at least one executing user). Save.
5. Confirm: the event appears on the planner, and navigating to Service Orders shows a new bare service order for the selected customer in the first pipeline stage.
6. Uncheck the checkbox — confirm the ComboBox returns and a normal event+SO link save still works.

#### Step 6 — Commit

```bash
git add resources/js/Components/Planner/EventEditModal.vue
git commit -m "feat(planner): add checkbox to create new werkbon on event creation"
```
