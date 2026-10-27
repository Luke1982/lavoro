# Override Unavailability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow planners to override user unavailability blocks in the resource planner, gated behind an admin toggle, with a confirmation dialog that names the affected user(s).

**Architecture:** A `GeneralSetting` key-value pair controls the feature. The backend passes it as a boolean prop through `PlannerController` → `Planner/IndexPage` → `ResourcePlannerWidget`. Inside the widget, a pending-callback pattern lets synchronous pointer/drag handlers trigger an async-feeling dialog without restructuring the event loop. A new `UnavailabilityOverrideDialog` component handles the UI.

**Tech Stack:** Laravel 12, Inertia, Vue 3 Composition API, HeadlessUI (`@headlessui/vue`), Lucide (`@lucide/vue`), Tailwind CSS with Lavoro custom colours.

## Global Constraints

- All PHP variables: snake_case.
- No inline comments in PHP or Vue.
- No tests unless explicitly requested (the project has very few; don't add them here).
- Do NOT commit — user reviews the finished product first.
- Dutch UI copy throughout (labels, button text, flash messages).
- Lavoro primary colour: `bg-lavoro-blue` / `text-lavoro-blue` (hex `#2563ff`).
- Icon library for the new dialog: `@lucide/vue` (already installed). Import named exports directly: `import { AlertTriangle } from '@lucide/vue'`.
- HeadlessUI already installed; follow the exact pattern used in `resources/js/Components/UI/ModalDialog.vue`.
- `SwitchComponent` already exists at `resources/js/Components/UI/SwitchComponent.vue`; use it as-is.

---

## File Map

| File | Action |
|---|---|
| `app/Http/Requests/Admin/UpdateAllowOverrideUnavailabilityRequest.php` | Create |
| `app/Http/Controllers/Admin/GeneralSettingsController.php` | Modify — add `updateAllowOverrideUnavailability` method and expose setting in `index` |
| `routes/web.php` | Modify — add `PUT admin/settings/allow-override-unavailability` |
| `app/Http/Controllers/PlannerController.php` | Modify — pass `allowOverrideUnavailability` prop |
| `resources/js/Pages/Planner/IndexPage.vue` | Modify — accept and forward `allowOverrideUnavailability` prop |
| `resources/js/Components/Planner/UnavailabilityOverrideDialog.vue` | Create |
| `resources/js/Components/Planner/ResourcePlannerWidget.vue` | Modify — prop, helper, dialog state, 5 interception points |
| `resources/js/Pages/Admin/GeneralSettingsPage.vue` | Modify — Planning section with switch |

---

### Task 1: Form Request + Controller method + Route

**Files:**
- Create: `app/Http/Requests/Admin/UpdateAllowOverrideUnavailabilityRequest.php`
- Modify: `app/Http/Controllers/Admin/GeneralSettingsController.php`
- Modify: `routes/web.php`

**Interfaces:**
- Produces: `PUT /admin/settings/allow-override-unavailability` accepts `{ value: bool }`, returns redirect back with flash `'success'`.
- Produces: `GeneralSettingsController::index()` now includes `'allowOverrideUnavailability' => bool` in the Inertia response.

- [ ] **Step 1: Create the Form Request**

Create `app/Http/Requests/Admin/UpdateAllowOverrideUnavailabilityRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAllowOverrideUnavailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'value' => ['required', 'boolean'],
        ];
    }
}
```

- [ ] **Step 2: Add the controller method and expose the setting in `index`**

Open `app/Http/Controllers/Admin/GeneralSettingsController.php`.

Add the import at the top (after the existing `use` statements):
```php
use App\Http\Requests\Admin\UpdateAllowOverrideUnavailabilityRequest;
```

Replace the `index` method body so it also returns `allowOverrideUnavailability`:
```php
public function index(): Response
{
    return Inertia::render('Admin/GeneralSettingsPage', [
        'locationTracking' => [
            'start' => GeneralSetting::get('location_tracking_start', '07:00'),
            'end'   => GeneralSetting::get('location_tracking_end', '18:00'),
            'days'  => array_map('intval', explode(',', GeneralSetting::get('location_tracking_days', '1,2,3,4,5'))),
        ],
        'serviceOrderClosingText'       => GeneralSetting::get('serviceorder_closing_text', ''),
        'allowOverrideUnavailability'   => GeneralSetting::get('allow_override_unavailability', '0') === '1',
    ]);
}
```

Add the new method at the bottom of the class (before the closing `}`):
```php
public function updateAllowOverrideUnavailability(UpdateAllowOverrideUnavailabilityRequest $request): RedirectResponse
{
    GeneralSetting::set('allow_override_unavailability', $request->validated()['value'] ? '1' : '0');

    return redirect()->back()->with('success', 'Instellingen opgeslagen.');
}
```

- [ ] **Step 3: Register the route**

Open `routes/web.php`. Find the block ending with the `serviceorder-closing-text` route (around line 324). Add immediately after it, before the closing `});`:

```php
Route::put(
    'admin/settings/allow-override-unavailability',
    [GeneralSettingsController::class, 'updateAllowOverrideUnavailability'],
)->name('admin.settings.allow-override-unavailability');
```

- [ ] **Step 4: Smoke-test the route exists**

```bash
php artisan route:list --name=admin.settings.allow-override-unavailability
```

Expected output: one row showing `PUT` · `admin/settings/allow-override-unavailability`.

---

### Task 2: Admin UI — Planning section in GeneralSettingsPage

**Files:**
- Modify: `resources/js/Pages/Admin/GeneralSettingsPage.vue`

**Interfaces:**
- Consumes: `allowOverrideUnavailability: Boolean` prop from Inertia (set in Task 1).
- Consumes: `SwitchComponent` from `@/Components/UI/SwitchComponent.vue`.
- Sends: `PUT /admin/settings/allow-override-unavailability` with `{ value: bool }`.

- [ ] **Step 1: Add the prop, form, and section**

Replace the entire content of `resources/js/Pages/Admin/GeneralSettingsPage.vue` with:

```vue
<template>
    <div class="p-6 max-w-2xl">
        <h1 class="text-xl font-semibold text-gray-900 mb-6">Instellingen</h1>

        <section>
            <h2 class="text-base font-semibold text-gray-900 mb-4">Locatie tracking</h2>

            <form @submit.prevent="submit" class="space-y-5">
                <div class="flex gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Starttijd</label>
                        <input type="time" v-model="form.start"
                            class="rounded-md border-gray-300 shadow-sm text-sm focus:border-lavoro-blue focus:ring-lavoro-blue bg-white" />
                        <p v-if="form.errors.start" class="mt-1 text-xs text-red-600">{{ form.errors.start }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Eindtijd</label>
                        <input type="time" v-model="form.end"
                            class="rounded-md border-gray-300 shadow-sm text-sm focus:border-lavoro-blue focus:ring-lavoro-blue bg-white" />
                        <p v-if="form.errors.end" class="mt-1 text-xs text-red-600">{{ form.errors.end }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Dagen</label>
                    <div class="flex gap-2 flex-wrap">
                        <button v-for="day in DAYS" :key="day.value" type="button" @click="toggleDay(day.value)" :class="[
                            'px-3 py-1.5 rounded-md text-sm font-medium border transition-colors',
                            form.days.includes(day.value)
                                ? 'bg-lavoro-blue border-lavoro-blue text-white'
                                : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                        ]">
                            {{ day.label }}
                        </button>
                    </div>
                    <p v-if="form.errors.days" class="mt-1 text-xs text-red-600">{{ form.errors.days }}</p>
                </div>

                <div>
                    <button type="submit" :disabled="form.processing"
                        class="inline-flex items-center rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-lavoro-blue disabled:opacity-50">
                        Opslaan
                    </button>
                </div>
            </form>
        </section>

        <section class="mt-10">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Werkbon</h2>

            <form @submit.prevent="submitClosingText" class="space-y-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Afsluitende tekst</label>
                    <p class="text-xs text-gray-500 mb-2">
                        Deze tekst wordt onderaan de werkbon-PDF getoond, direct boven de voettekst.
                    </p>
                    <textarea v-model="closingTextForm.serviceorder_closing_text" rows="5"
                        class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-lavoro-blue focus:ring-lavoro-blue bg-white"></textarea>
                    <p v-if="closingTextForm.errors.serviceorder_closing_text" class="mt-1 text-xs text-red-600">
                        {{ closingTextForm.errors.serviceorder_closing_text }}
                    </p>
                </div>

                <div>
                    <button type="submit" :disabled="closingTextForm.processing"
                        class="inline-flex items-center rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-lavoro-blue disabled:opacity-50">
                        Opslaan
                    </button>
                </div>
            </form>
        </section>

        <section class="mt-10">
            <h2 class="text-base font-semibold text-gray-900 mb-4">Planning</h2>

            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-700">Onbeschikbaarheid overschrijven toestaan</p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Wanneer ingeschakeld kunnen planners een afspraak inplannen op een geblokkeerd tijdslot na bevestiging.
                    </p>
                </div>
                <SwitchComponent v-model="overrideForm.value" @update="submitOverride" />
            </div>
        </section>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'

const props = defineProps({
    locationTracking: { type: Object, required: true },
    serviceOrderClosingText: { type: String, default: '' },
    allowOverrideUnavailability: { type: Boolean, default: false },
})

const DAYS = [
    { value: 1, label: 'Ma' },
    { value: 2, label: 'Di' },
    { value: 3, label: 'Wo' },
    { value: 4, label: 'Do' },
    { value: 5, label: 'Vr' },
    { value: 6, label: 'Za' },
    { value: 7, label: 'Zo' },
]

const form = useForm({
    start: props.locationTracking.start,
    end: props.locationTracking.end,
    days: [...props.locationTracking.days],
})

function toggleDay(value) {
    const index = form.days.indexOf(value)
    if (index === -1) {
        form.days.push(value)
    } else {
        form.days.splice(index, 1)
    }
}

const closingTextForm = useForm({
    serviceorder_closing_text: props.serviceOrderClosingText,
})

const overrideForm = useForm({
    value: props.allowOverrideUnavailability,
})

function submit() {
    form.put('/admin/settings/location-tracking')
}

function submitClosingText() {
    closingTextForm.put('/admin/settings/serviceorder-closing-text')
}

function submitOverride() {
    overrideForm.put('/admin/settings/allow-override-unavailability')
}
</script>
```

- [ ] **Step 2: Verify it renders**

Navigate to `/admin/settings` in the browser. Confirm a "Planning" section appears with a labelled switch. Toggle it on and off — the network tab should show a `PUT` to `admin/settings/allow-override-unavailability`. Page reloads with the saved state.

---

### Task 3: Expose setting via PlannerController + IndexPage prop

**Files:**
- Modify: `app/Http/Controllers/PlannerController.php`
- Modify: `resources/js/Pages/Planner/IndexPage.vue`

**Interfaces:**
- Produces: `allowOverrideUnavailability: Boolean` Inertia prop available on the planner page.
- Produces: `ResourcePlannerWidget` receives `:allow-override-unavailability="props.allowOverrideUnavailability"`.

- [ ] **Step 1: Add the prop to PlannerController**

Open `app/Http/Controllers/PlannerController.php`. Inside the `return inertia(...)` call, add after `'defaultPlannerMinutes'`:

```php
'allowOverrideUnavailability' => GeneralSetting::get('allow_override_unavailability', '0') === '1',
```

- [ ] **Step 2: Accept and forward the prop in IndexPage**

Open `resources/js/Pages/Planner/IndexPage.vue`.

Add to the `defineProps` block (after `latestPings`):
```js
allowOverrideUnavailability: { type: Boolean, default: false },
```

Add the attribute to the `<ResourcePlannerWidget>` component in the template (after `:latest-pings`):
```html
:allow-override-unavailability="props.allowOverrideUnavailability"
```

---

### Task 4: UnavailabilityOverrideDialog component

**Files:**
- Create: `resources/js/Components/Planner/UnavailabilityOverrideDialog.vue`

**Interfaces:**
- Props: `open: Boolean`, `users: Array<{ name: string, label: string | null }>`
- Emits: `'confirm'`, `'cancel'`
- Consumed by: `ResourcePlannerWidget` (Task 5).

- [ ] **Step 1: Create the component**

Create `resources/js/Components/Planner/UnavailabilityOverrideDialog.vue`:

```vue
<template>
    <TransitionRoot as="template" :show="open">
        <Dialog class="relative z-50" @close="$emit('cancel')">
            <TransitionChild as="template"
                enter="ease-out duration-300" enter-from="opacity-0" enter-to="opacity-100"
                leave="ease-in duration-200" leave-from="opacity-100" leave-to="opacity-0">
                <div class="fixed inset-0 bg-gray-500/75 transition-opacity" />
            </TransitionChild>

            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <TransitionChild as="template"
                        enter="ease-out duration-300"
                        enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        enter-to="opacity-100 translate-y-0 sm:scale-100"
                        leave="ease-in duration-200"
                        leave-from="opacity-100 translate-y-0 sm:scale-100"
                        leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
                        <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                <div class="sm:flex sm:items-start">
                                    <div class="mx-auto flex size-12 shrink-0 items-center justify-center rounded-full bg-amber-100 sm:mx-0 sm:size-10">
                                        <AlertTriangle class="size-6 text-amber-600" aria-hidden="true" />
                                    </div>
                                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                        <DialogTitle as="h3" class="text-base font-semibold text-gray-900">
                                            Niet beschikbaar
                                        </DialogTitle>
                                        <div class="mt-2">
                                            <p class="text-sm text-gray-500">
                                                Je staat op het punt een afspraak in te plannen op een tijdstip waarop de volgende monteur(s) niet beschikbaar zijn:
                                            </p>
                                            <ul class="mt-2 space-y-1">
                                                <li v-for="(u, i) in users" :key="i" class="text-sm font-medium text-gray-700">
                                                    {{ u.name }}<span v-if="u.label" class="font-normal text-gray-500"> — {{ u.label }}</span>
                                                </li>
                                            </ul>
                                            <p class="mt-3 text-sm text-gray-500">Wil je toch doorgaan?</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                                <button type="button"
                                    class="inline-flex w-full justify-center rounded-md bg-lavoro-blue px-3 py-2 text-sm font-semibold text-white shadow-xs hover:opacity-90 sm:ml-3 sm:w-auto"
                                    @click="$emit('confirm')">
                                    Doorgaan
                                </button>
                                <button type="button"
                                    class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-xs ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto"
                                    @click="$emit('cancel')">
                                    Annuleren
                                </button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<script setup>
import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue'
import { AlertTriangle } from '@lucide/vue'

defineProps({
    open: { type: Boolean, required: true },
    users: { type: Array, default: () => [] },
})

defineEmits(['confirm', 'cancel'])
</script>
```

---

### Task 5: Wire everything into ResourcePlannerWidget

**Files:**
- Modify: `resources/js/Components/Planner/ResourcePlannerWidget.vue`

**Interfaces:**
- Consumes: `UnavailabilityOverrideDialog` (Task 4).
- Consumes: `allowOverrideUnavailability: Boolean` prop (Task 3).

This task has multiple steps that each touch a different part of the file. Work through them in order.

- [ ] **Step 1: Add the import and prop**

In the `<script setup>` block, add the import after the existing component imports (near the `PlannerExportDrawer` import):
```js
import UnavailabilityOverrideDialog from '@/Components/Planner/UnavailabilityOverrideDialog.vue'
```

Add to `defineProps` (after `latestPings`):
```js
allowOverrideUnavailability: { type: Boolean, default: false },
```

- [ ] **Step 2: Add dialog state and helpers**

After the `exportDrawerOpen` ref declaration, add:

```js
const unavailOverrideDialog = ref({ open: false, users: [] })
let pendingOverrideAction = null

function getBlockedUsers(userId, dayIso, startMin, endMin) {
    const absStart = startMin + dayStartHour.value * 60
    const absEnd = endMin + dayStartHour.value * 60
    const user = props.plannableUsers.find(u => u.id === userId)
    if (!user) return []
    return user.unavailabilities
        .filter(unav => {
            if (!unavailabilityMatchesDay(unav, dayIso)) return false
            if (unav.start_time === null) return true
            const [sh, sm] = unav.start_time.split(':').map(Number)
            const [eh, em] = unav.end_time.split(':').map(Number)
            return absStart < eh * 60 + em && absEnd > sh * 60 + sm
        })
        .map(unav => ({ name: user.name, label: unav.label }))
}

function requestOverride(affectedUsers, actionFn) {
    unavailOverrideDialog.value = { open: true, users: affectedUsers }
    pendingOverrideAction = actionFn
}

function onOverrideConfirm() {
    unavailOverrideDialog.value = { open: false, users: [] }
    const fn = pendingOverrideAction
    pendingOverrideAction = null
    fn?.()
}

function onOverrideCancel() {
    unavailOverrideDialog.value = { open: false, users: [] }
    pendingOverrideAction = null
}
```

- [ ] **Step 3: Modify `onCellPointerDown`**

Find this line inside `onCellPointerDown` (currently line ~1081):
```js
if (isBlockedAtTime(user.id, day.iso, snapMinutes(info.minutes), snapMinutes(info.minutes) + slotMinutes.value)) return
```

Replace it with:
```js
if (isBlockedAtTime(user.id, day.iso, snapMinutes(info.minutes), snapMinutes(info.minutes) + slotMinutes.value)) {
    if (!props.allowOverrideUnavailability) return
}
```

- [ ] **Step 4: Modify `onWindowPointerUp` — select branch**

Find the select branch in `onWindowPointerUp`. It currently ends with:
```js
    openCreate({ start, end, userId: sel.userId })
    return
```

The full select branch currently reads:
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

Replace it with:
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
    if (isBlockedAtTime(sel.userId, sel.dayIso, startMin, endMin)) {
        if (props.allowOverrideUnavailability) {
            const blockedUsers = getBlockedUsers(sel.userId, sel.dayIso, startMin, endMin)
            requestOverride(blockedUsers, () => openCreate({ start, end, userId: sel.userId }))
        }
        return
    }
    openCreate({ start, end, userId: sel.userId })
    return
}
```

- [ ] **Step 5: Modify `onWindowPointerUp` — move/resize branch**

Find the move/resize branch. It currently begins with:
```js
if (mode === 'move' || mode === 'resize') {
    const ev = drag.value.originalEvent
    const previewStart = drag.value.previewStart
    const previewEnd = drag.value.previewEnd
    const previewUserId = drag.value.previewUserId
```

Replace the entire move/resize branch with:
```js
if (mode === 'move' || mode === 'resize') {
    const ev = drag.value.originalEvent
    const previewStart = drag.value.previewStart
    const previewEnd = drag.value.previewEnd
    const previewUserId = drag.value.previewUserId
    const previewDayIso = drag.value.previewDayIso
    const movedTime = previewStart.getTime() !== ev.start.getTime() ||
        previewEnd.getTime() !== ev.end.getTime()
    const movedUser = !drag.value.isLocked && mode === 'move' &&
        !ev.executing_user_ids.includes(previewUserId)
    dragGhost.value = null
    drag.value = { eventId: null, mode: null }
    if (movedTime || movedUser) {
        suppressClickUntil = Date.now() + 300
        if (previewDayIso && isBlockedAtTime(previewUserId, previewDayIso, minutesFromDayStart(previewStart), minutesFromDayStart(previewEnd))) {
            if (props.allowOverrideUnavailability) {
                const blockedUsers = getBlockedUsers(previewUserId, previewDayIso, minutesFromDayStart(previewStart), minutesFromDayStart(previewEnd))
                requestOverride(blockedUsers, () => persistEventChange(ev, previewStart, previewEnd, movedUser ? previewUserId : null))
            }
            return
        }
        await persistEventChange(ev, previewStart, previewEnd, movedUser ? previewUserId : null)
    }
}
```

- [ ] **Step 6: Modify `onDragOver`**

Find the current `onDragOver` function:
```js
function onDragOver(e) {
    if (!(e.dataTransfer && e.dataTransfer.types?.includes('application/x-planner-payload'))) return
    dragPointerY = e.clientY
    startDragAutoScroll()
    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (info) {
        const startMin = dropStartMinutes(info, plannerMinutes.value)
        if (isBlockedAtTime(info.userId, info.dayIso, startMin, startMin + plannerMinutes.value)) {
            e.dataTransfer.dropEffect = 'none'
            dragGhost.value = null
            return
        }
    }
    e.dataTransfer.dropEffect = 'copy'
    updateExternalDropGhost(e.clientX, e.clientY)
}
```

Replace with:
```js
function onDragOver(e) {
    if (!(e.dataTransfer && e.dataTransfer.types?.includes('application/x-planner-payload'))) return
    dragPointerY = e.clientY
    startDragAutoScroll()
    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (info) {
        const startMin = dropStartMinutes(info, plannerMinutes.value)
        if (isBlockedAtTime(info.userId, info.dayIso, startMin, startMin + plannerMinutes.value)) {
            if (!props.allowOverrideUnavailability) {
                e.dataTransfer.dropEffect = 'none'
                dragGhost.value = null
                return
            }
        }
    }
    e.dataTransfer.dropEffect = 'copy'
    updateExternalDropGhost(e.clientX, e.clientY)
}
```

- [ ] **Step 7: Modify `onExternalDrop`**

Find the current `onExternalDrop` function. It currently contains:
```js
    if (isBlockedAtTime(user.id, day.iso, startMin, startMin + duration)) return
    const start = dateFromDayIsoAndMinutes(day.iso, startMin)
    const end = new Date(start.getTime() + duration * 60000)
    createEventFromDrop({ start, end, userId: user.id, payload })
```

Replace those final lines with:
```js
    const start = dateFromDayIsoAndMinutes(day.iso, startMin)
    const end = new Date(start.getTime() + duration * 60000)
    if (isBlockedAtTime(user.id, day.iso, startMin, startMin + duration)) {
        if (props.allowOverrideUnavailability) {
            const blockedUsers = getBlockedUsers(user.id, day.iso, startMin, startMin + duration)
            requestOverride(blockedUsers, () => createEventFromDrop({ start, end, userId: user.id, payload }))
        }
        return
    }
    createEventFromDrop({ start, end, userId: user.id, payload })
```

- [ ] **Step 8: Add dialog to the template**

Find the `<PlannerExportDrawer>` line near the bottom of the `<template>`. Add immediately after it:

```html
<UnavailabilityOverrideDialog
    :open="unavailOverrideDialog.open"
    :users="unavailOverrideDialog.users"
    @confirm="onOverrideConfirm"
    @cancel="onOverrideCancel" />
```

- [ ] **Step 9: Manual end-to-end verification**

With the admin setting toggled **OFF**:
1. Open the planner. Click a blocked (striped) slot → nothing happens. ✓
2. Drag a service order onto a blocked slot → nothing happens. ✓
3. Drag an existing event onto a blocked slot → nothing happens. ✓

With the admin setting toggled **ON**:
4. Click a blocked slot → dialog appears listing the user name (+label if set). Click "Annuleren" → nothing happens. Click "Doorgaan" → create modal opens. ✓
5. Click and drag across a blocked slot → dialog appears on release. Confirm → create modal opens with the selected range. ✓
6. Drag a service order onto a blocked slot → ghost is visible while hovering. On drop, dialog appears. Confirm → event is created on the striped background. ✓
7. Drag an existing event onto a blocked slot → dialog appears on release. Confirm → event moves and persists. ✓
8. In all cases, the gray striped overlay remains visible behind placed events. ✓
