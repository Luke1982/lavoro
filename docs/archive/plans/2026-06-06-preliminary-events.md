# Preliminary Events Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a boolean `is_preliminary` flag to events, surfaced as a "Voorlopig" checkbox in the edit modal, and rendered in the planner with a dashed border, lighter background, and warning icon.

**Architecture:** Add `is_preliminary` as a DB column → model fillable + cast → form request rules → EventEditModal checkbox → PlannerEvent visual treatment. EventApiController needs no changes (Eloquent serializes the column automatically).

**Tech Stack:** Laravel 12, Inertia/Vue 3, Heroicons, Tailwind CSS

---

### Task 1: Migration + Model

**Files:**
- Create: `database/migrations/2026_06_06_200000_add_is_preliminary_to_events_table.php`
- Modify: `app/Models/Event.php`

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
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('is_preliminary')->default(false)->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('is_preliminary');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_06_06_200000_add_is_preliminary_to_events_table` → `Migrated`.

- [ ] **Step 3: Update Event model**

In `app/Models/Event.php`, add `'is_preliminary'` to `$fillable` and add a bool cast:

```php
protected $fillable = [
    'name',
    'description',
    'event_type_id',
    'start',
    'end',
    'status',
    'location',
    'origin',
    'is_preliminary',
];

protected $casts = [
    'start'          => 'datetime',
    'end'            => 'datetime',
    'is_preliminary' => 'boolean',
];
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_06_200000_add_is_preliminary_to_events_table.php app/Models/Event.php
git commit -m "feat(events): add is_preliminary boolean column and model support"
```

---

### Task 2: Form Request Validation

**Files:**
- Modify: `app/Http/Requests/EventStoreRequest.php`
- Modify: `app/Http/Requests/EventUpdateRequest.php`

- [ ] **Step 1: Add rule to EventStoreRequest**

In the `rules()` method of `app/Http/Requests/EventStoreRequest.php`, add:

```php
'is_preliminary' => 'nullable|boolean',
```

- [ ] **Step 2: Add rule to EventUpdateRequest**

In the `rules()` method of `app/Http/Requests/EventUpdateRequest.php`, add:

```php
'is_preliminary' => ['sometimes', 'boolean'],
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/EventStoreRequest.php app/Http/Requests/EventUpdateRequest.php
git commit -m "feat(events): validate is_preliminary in store and update requests"
```

---

### Task 3: Pass is_preliminary Through the Edit Modal

**Files:**
- Modify: `resources/js/Components/Planner/ResourcePlannerWidget.vue`
- Modify: `resources/js/Components/Planner/EventEditModal.vue`

- [ ] **Step 1: Add is_preliminary to openEdit in ResourcePlannerWidget.vue**

Find the `openEdit` function (around line 1274). Add `is_preliminary` to the `modalInitial.value` object:

```js
function openEdit(ev) {
    editingExistingEvent.value = true
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
        is_preliminary: ev.is_preliminary || false,
    }
    modalOpen.value = true
}
```

- [ ] **Step 2: Add is_preliminary to the form in EventEditModal.vue**

Find the `useForm({...})` call (around line 278). Add `is_preliminary`:

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
    is_preliminary: props.initial.is_preliminary || false,
})
```

- [ ] **Step 3: Add ExclamationTriangleIcon import to EventEditModal.vue**

In the heroicons import block (around line 250):

```js
import {
    XMarkIcon, CalendarDaysIcon, TagIcon, CheckCircleIcon, DocumentTextIcon,
    UserIcon, DocumentIcon, BuildingOffice2Icon, Bars3BottomLeftIcon,
    UsersIcon, PlusIcon, CheckIcon, ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'
```

- [ ] **Step 4: Add the "Voorlopig" checkbox in the modal template**

Find the status section in the template (around line 78, the `<!-- Status -->` block). After the closing `</div>` of the status `<div>` and before the next section (`<!-- Titel / Klant -->`), add a new row:

```html
<!-- Voorlopig -->
<div class="flex items-center gap-3 py-1">
    <input
        id="is_preliminary"
        type="checkbox"
        v-model="form.is_preliminary"
        class="h-4 w-4 rounded border-gray-300 text-lavoro-blue focus:ring-lavoro-blue cursor-pointer"
    />
    <label for="is_preliminary" class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer select-none">
        <ExclamationTriangleIcon class="h-4 w-4 text-amber-500" />
        Voorlopig
    </label>
</div>
```

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Planner/ResourcePlannerWidget.vue resources/js/Components/Planner/EventEditModal.vue
git commit -m "feat(events): add Voorlopig checkbox to event edit modal"
```

---

### Task 4: Planner Visual Rendering

**Files:**
- Modify: `resources/js/Components/Planner/PlannerEvent.vue`

- [ ] **Step 1: Add ExclamationTriangleIcon import**

Find the import block (around line 57-59). Add `ExclamationTriangleIcon`:

```js
import { computed } from 'vue'
import { ClockIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { nlTime } from '@/Utilities/Utilities'
```

- [ ] **Step 2: Update the style computed property**

Find the `style` computed property (around line 88). Update it to apply preliminary styles when `props.event.is_preliminary` is true:

```js
const style = computed(() => {
    const startMin = Math.max(0, minutesFromDayStart(props.event.start))
    const endMin = Math.min(totalMin.value, minutesFromDayStart(props.event.end))
    const leftPct = (startMin / totalMin.value) * 100
    const widthPct = Math.max(2, ((endMin - startMin) / totalMin.value) * 100)
    const color = props.event.color || '#3b82f6'
    const bgStrength = props.event.is_preliminary ? '8%' : '18%'
    return {
        left: leftPct + '%',
        width: widthPct + '%',
        top: props.eventPaddingY + 'px',
        bottom: props.eventPaddingY + 'px',
        backgroundColor: `color-mix(in srgb, ${color} ${bgStrength}, white)`,
        borderColor: color,
        borderLeftStyle: props.event.is_preliminary ? 'dashed' : 'solid',
        transition: 'top 200ms ease-in-out, bottom 200ms ease-in-out',
    }
})
```

- [ ] **Step 3: Add warning icon to the title row in the template**

Find the title `<div>` in the template (around line 16):

```html
<div class="text-xs font-semibold leading-tight truncate">
    #{{ event.id }} {{ event.name || event.title }}
</div>
```

Replace it with:

```html
<div class="text-xs font-semibold leading-tight truncate flex items-center gap-1">
    <ExclamationTriangleIcon v-if="event.is_preliminary" class="size-3 shrink-0 text-amber-500" />
    #{{ event.id }} {{ event.name || event.title }}
</div>
```

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Planner/PlannerEvent.vue
git commit -m "feat(planner): render preliminary events with dashed border, lighter bg, and warning icon"
```
