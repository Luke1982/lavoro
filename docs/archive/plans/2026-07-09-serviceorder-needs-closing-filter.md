# Serviceorder "needs closing" filter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a switch to the serviceorder index that filters to only orders whose non-cancelled events have all already ended, but whose stage isn't a closed stage yet.

**Architecture:** Backend: one new boolean request field plus an inline `whereHas`/`whereDoesntHave` query addition in `ServiceOrderController::index`, mirroring the existing inline `only_stages` filter. Frontend: one new `SwitchComponent` in `ServiceOrders/IndexPage.vue`'s filter slot, wired through the same URL-query + `localStorage` persistence pattern already used for the stage filter.

**Tech Stack:** Laravel 12 (Eloquent, Form Requests), Inertia + Vue 3.

## Global Constraints

- PHP: snake_case for all variable names.
- No inline comments; prefer clear names and docblocks only when needed.
- Validation lives in Form Request `rules()` only; frontend only displays `form.errors`.
- Selecting/toggling in UI components: clicking a selected item deselects it — never add separate X / clear buttons (the switch toggles itself; only the filter-chip's own X clears it, matching the existing stage-filter chip behavior).
- String concatenation always uses spaces around `.`.
- Don't write automated tests unless asked — verify manually instead.
- Don't propose git commands or workflows; each task ends with a plain description of what to stage/commit, for the user to run themselves.

---

### Task 1: Backend filter — request validation + controller query

**Files:**
- Modify: `app/Http/Requests/ServiceOrderIndexRequest.php`
- Modify: `app/Http/Controllers/ServiceOrderController.php:1-93` (imports + `index` method)

**Interfaces:**
- Produces: request param `onlyNeedsClosing` (boolean, optional). Inertia prop `onlyNeedsClosing: bool`, echoed back on `ServiceOrders/IndexPage` exactly like the existing `onlyStage` prop.

- [ ] **Step 1: Add the validation rule**

In `app/Http/Requests/ServiceOrderIndexRequest.php`, add `onlyNeedsClosing` to `rules()`:

```php
public function rules(): array
{
    return [
        'search'           => ['sometimes', 'nullable', 'string', 'max:255'],
        'onlyStage'        => ['sometimes', 'nullable', 'string'],
        'onlyNeedsClosing' => ['sometimes', 'nullable', 'boolean'],
        'perPage'          => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
    ];
}
```

- [ ] **Step 2: Add the `EventStatusses` import**

In `app/Http/Controllers/ServiceOrderController.php`, add the import alphabetically before the existing `App\Enums\ServiceJobOutcomes` import:

```php
use App\Enums\EventStatusses;
use App\Enums\ServiceJobOutcomes;
```

- [ ] **Step 3: Add the query filter in `index()`**

In `app/Http/Controllers/ServiceOrderController.php`, the `index` method currently reads (around line 51):

```php
    public function index(ServiceOrderIndexRequest $request)
    {
        $search = $request->get('search', '');
        $only_stages = array_values(array_filter(
            explode(',', (string) $request->get('onlyStage', '')),
            fn ($v) => is_numeric($v)
        ));
        $per_page = $this->perPage($request, 25);

        $user = Auth::user();
        $query = ServiceOrder::with([
            'customer',
            'serviceOrderStage',
            'events' => fn ($q) => $q->orderBy('start'),
            'events.executingUsers:id,name',
        ]);

        if (!$user->isAdmin() && !$user->hasPermission('serviceorder.read')) {
            $query->whereHas('executingUsers', fn ($q) => $q->where('users.id', $user->id));
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('external_purchaseorder_no', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if (count($only_stages)) {
            $query->whereIn('service_order_stage_id', $only_stages);
        }

        return inertia('ServiceOrders/IndexPage', [
            'serviceOrders' => $query->orderByDesc('created_at')->paginate($per_page)->withQueryString(),
            'stages' => ServiceOrderStage::orderBy('order')->get(),
            'search' => $search,
            'onlyStage' => $only_stages,
            'perPage' => $per_page,
        ]);
    }
```

Replace it with (new `$only_needs_closing` variable, the new `if` block after the `$only_stages` block, and the new prop in the returned array):

```php
    public function index(ServiceOrderIndexRequest $request)
    {
        $search = $request->get('search', '');
        $only_stages = array_values(array_filter(
            explode(',', (string) $request->get('onlyStage', '')),
            fn ($v) => is_numeric($v)
        ));
        $only_needs_closing = $request->boolean('onlyNeedsClosing');
        $per_page = $this->perPage($request, 25);

        $user = Auth::user();
        $query = ServiceOrder::with([
            'customer',
            'serviceOrderStage',
            'events' => fn ($q) => $q->orderBy('start'),
            'events.executingUsers:id,name',
        ]);

        if (!$user->isAdmin() && !$user->hasPermission('serviceorder.read')) {
            $query->whereHas('executingUsers', fn ($q) => $q->where('users.id', $user->id));
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('external_purchaseorder_no', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if (count($only_stages)) {
            $query->whereIn('service_order_stage_id', $only_stages);
        }

        if ($only_needs_closing) {
            $query->whereHas('events', fn ($q) => $q->where('status', '!=', EventStatusses::cancelled->value))
                ->whereDoesntHave('events', fn ($q) => $q->where('status', '!=', EventStatusses::cancelled->value)->where('end', '>=', now()))
                ->whereDoesntHave('serviceOrderStage', fn ($q) => $q->where('is_closed_state', true));
        }

        return inertia('ServiceOrders/IndexPage', [
            'serviceOrders' => $query->orderByDesc('created_at')->paginate($per_page)->withQueryString(),
            'stages' => ServiceOrderStage::orderBy('order')->get(),
            'search' => $search,
            'onlyStage' => $only_stages,
            'onlyNeedsClosing' => $only_needs_closing,
            'perPage' => $per_page,
        ]);
    }
```

- [ ] **Step 4: Fix code style**

Run: `./vendor/bin/pint app/Http/Requests/ServiceOrderIndexRequest.php app/Http/Controllers/ServiceOrderController.php`
Expected: exits 0, reformats only if needed.

- [ ] **Step 5: Verify manually**

Run: `php artisan tinker --execute="
\$stage = App\Models\ServiceOrderStage::factory()->create(['is_closed_state' => false]);
\$so = App\Models\ServiceOrder::factory()->create(['service_order_stage_id' => \$stage->id]);
\$event = App\Models\Event::factory()->create(['start' => now()->subDays(2), 'end' => now()->subDay(), 'status' => 'Afgerond']);
\$event->serviceOrders()->attach(\$so->id);
echo App\Models\ServiceOrder::whereHas('events', fn (\$q) => \$q->where('status', '!=', 'Geannuleerd'))->whereDoesntHave('events', fn (\$q) => \$q->where('status', '!=', 'Geannuleerd')->where('end', '>=', now()))->whereDoesntHave('serviceOrderStage', fn (\$q) => \$q->where('is_closed_state', true))->whereKey(\$so->id)->exists() ? 'MATCH' : 'NO MATCH';
"`
Expected output: `MATCH`. If the `events()` relation name on `Event` differs (check `app/Models/Event.php` for the inverse morph-to-many, e.g. it may be `serviceOrders()` or a generic `eventables`-based accessor), adjust the attach call accordingly before running — the assertion query itself does not depend on that name.

- [ ] **Step 6: Commit**

Files to stage: `app/Http/Requests/ServiceOrderIndexRequest.php`, `app/Http/Controllers/ServiceOrderController.php`. Suggested message: `feat(serviceorders): add onlyNeedsClosing filter to index query`. Wait for the user's go-ahead before committing, per project convention.

---

### Task 2: Frontend switch — UI, persistence, filter chip

**Files:**
- Modify: `resources/js/Pages/ServiceOrders/IndexPage.vue`

**Interfaces:**
- Consumes: Inertia prop `onlyNeedsClosing: bool` produced by Task 1. `SwitchComponent` from `@/Components/UI/SwitchComponent.vue` (existing component, `v-model` is `Boolean|null`, emits `update`).
- Produces: nothing consumed by later tasks (this is the last task).

- [ ] **Step 1: Add the prop**

In `resources/js/Pages/ServiceOrders/IndexPage.vue`, the `defineProps` block currently reads:

```javascript
const { serviceOrders, stages, perPage } = defineProps({
    serviceOrders: { type: Object, required: true },
    stages: { type: Array, default: () => [] },
    search: { type: String, default: '' },
    onlyStage: { type: Array, default: () => [] },
    perPage: { type: Number, default: 25 },
})
```

Replace it with:

```javascript
const { serviceOrders, stages, perPage } = defineProps({
    serviceOrders: { type: Object, required: true },
    stages: { type: Array, default: () => [] },
    search: { type: String, default: '' },
    onlyStage: { type: Array, default: () => [] },
    onlyNeedsClosing: { type: Boolean, default: false },
    perPage: { type: Number, default: 25 },
})
```

- [ ] **Step 2: Import `SwitchComponent`**

Add to the `<script setup>` import block, next to the other `@/Components/UI/*` imports:

```javascript
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
```

- [ ] **Step 3: Add the persisted `ref` and its URL/localStorage wiring**

The file currently has this block for the stage filter:

```javascript
const stagesFromUrl = typeof window !== 'undefined'
    ? (new URLSearchParams(window.location.search).get('onlyStage') || '').split(',').map(Number).filter(Boolean)
    : []
const stageFilter = ref(stagesFromUrl)

watch(stageFilter, val => {
    if (val.length) localStorage.setItem('serviceOrderFilter_stage', val.join(','))
    else localStorage.removeItem('serviceOrderFilter_stage')
})

onMounted(() => {
    if (stagesFromUrl.length) return
    const ls = (localStorage.getItem('serviceOrderFilter_stage') || '').split(',').map(Number).filter(Boolean)
    if (!ls.length) return
    stageFilter.value = ls
    router.get('/serviceorders', { onlyStage: ls.join(',') }, { replace: true, preserveState: true, preserveScroll: true })
})

const filterParams = computed(() => ({
    onlyStage: stageFilter.value.join(','),
}))
```

Replace it with (adds the `needsClosingFromUrl` constant, the `needsClosingFilter` ref, its own `watch`, folds the restore logic into the existing `onMounted`, and adds the field to `filterParams`):

```javascript
const stagesFromUrl = typeof window !== 'undefined'
    ? (new URLSearchParams(window.location.search).get('onlyStage') || '').split(',').map(Number).filter(Boolean)
    : []
const stageFilter = ref(stagesFromUrl)

const needsClosingFromUrl = typeof window !== 'undefined'
    ? new URLSearchParams(window.location.search).get('onlyNeedsClosing') === '1'
    : false
const needsClosingFilter = ref(needsClosingFromUrl)

watch(stageFilter, val => {
    if (val.length) localStorage.setItem('serviceOrderFilter_stage', val.join(','))
    else localStorage.removeItem('serviceOrderFilter_stage')
})

watch(needsClosingFilter, val => {
    if (val) localStorage.setItem('serviceOrderFilter_needsClosing', '1')
    else localStorage.removeItem('serviceOrderFilter_needsClosing')
})

onMounted(() => {
    const ls_stage = (localStorage.getItem('serviceOrderFilter_stage') || '').split(',').map(Number).filter(Boolean)
    const ls_needs_closing = localStorage.getItem('serviceOrderFilter_needsClosing') === '1'

    let restored_stage = false
    let restored_needs_closing = false

    if (!stagesFromUrl.length && ls_stage.length) {
        stageFilter.value = ls_stage
        restored_stage = true
    }
    if (!needsClosingFromUrl && ls_needs_closing) {
        needsClosingFilter.value = true
        restored_needs_closing = true
    }

    if (restored_stage || restored_needs_closing) {
        router.get('/serviceorders', {
            onlyStage: stageFilter.value.join(','),
            onlyNeedsClosing: needsClosingFilter.value ? '1' : undefined,
        }, { replace: true, preserveState: true, preserveScroll: true })
    }
})

const filterParams = computed(() => ({
    onlyStage: stageFilter.value.join(','),
    onlyNeedsClosing: needsClosingFilter.value ? '1' : undefined,
}))
```

- [ ] **Step 4: Add the switch to the filters chip list and `clearAllFilters`**

The file currently has:

```javascript
const activeFilters = computed(() => {
    return stageFilter.value.flatMap(id => {
        const match = stages.find(s => s.id === id)
        return match ? [{
            key: `stage-${id}`,
            label: 'Fase',
            value: match.name,
            clear: () => { stageFilter.value = stageFilter.value.filter(x => x !== id) },
        }] : []
    })
})

function clearAllFilters() {
    stageFilter.value = []
    localStorage.removeItem('serviceOrderFilter_stage')
}
```

Replace it with:

```javascript
const activeFilters = computed(() => {
    const stage_filters = stageFilter.value.flatMap(id => {
        const match = stages.find(s => s.id === id)
        return match ? [{
            key: `stage-${id}`,
            label: 'Fase',
            value: match.name,
            clear: () => { stageFilter.value = stageFilter.value.filter(x => x !== id) },
        }] : []
    })
    const needs_closing_filter = needsClosingFilter.value ? [{
        key: 'needs-closing',
        label: 'Te sluiten',
        value: 'Ja',
        clear: () => { needsClosingFilter.value = false },
    }] : []
    return [...stage_filters, ...needs_closing_filter]
})

function clearAllFilters() {
    stageFilter.value = []
    needsClosingFilter.value = false
    localStorage.removeItem('serviceOrderFilter_stage')
    localStorage.removeItem('serviceOrderFilter_needsClosing')
}
```

- [ ] **Step 5: Add the switch to the template**

In the `#filters` template slot, the file currently has:

```html
        <template #filters>
            <div class="flex flex-col sm:flex-row gap-y-4 sm:gap-y-0">
                <div class="flex-grow">
                    <ComboBox :options="stages" v-model="stageFilter" multiple placeholder="Selecteer fase(n)"
                        class="w-full" label="Filter op fase" />
                </div>
                <div class="hidden sm:flex w-1/6 items-end justify-end text-lavoro-blue font-semibold text-sm cursor-pointer"
                    @click="clearAllFilters">
                    <RotateCcwIcon class="h-5 w-5 mr-1" />Wis filters
                </div>
            </div>
```

Replace it with (adds a second row holding the switch and its helper text, below the stage combobox row):

```html
        <template #filters>
            <div class="flex flex-col sm:flex-row gap-y-4 sm:gap-y-0">
                <div class="flex-grow">
                    <ComboBox :options="stages" v-model="stageFilter" multiple placeholder="Selecteer fase(n)"
                        class="w-full" label="Filter op fase" />
                </div>
                <div class="hidden sm:flex w-1/6 items-end justify-end text-lavoro-blue font-semibold text-sm cursor-pointer"
                    @click="clearAllFilters">
                    <RotateCcwIcon class="h-5 w-5 mr-1" />Wis filters
                </div>
            </div>
            <div class="flex flex-col mt-4">
                <div class="flex items-center gap-3">
                    <SwitchComponent v-model="needsClosingFilter" />
                    <span class="text-sm font-bold text-gray-900 dark:text-slate-200">Alleen te sluiten</span>
                </div>
                <p class="text-xs text-gray-400 dark:text-slate-500 mt-1">
                    Toont werkbonnen waarvan alle afspraken al voorbij zijn maar die nog niet op een
                    gesloten fase staan. Geannuleerde afspraken tellen hierbij niet mee.
                </p>
            </div>
```

- [ ] **Step 6: Fix code style**

Run: `npm run fix:eslint`
Expected: exits 0.

- [ ] **Step 7: Verify manually in the browser**

Run: `composer run dev` (or ensure the existing dev stack is running), open `/serviceorders`.
- Toggle the new switch on: URL gains `onlyNeedsClosing=1`, list re-filters, a "Te sluiten: Ja" chip appears.
- Click the chip's X: switch turns back off, filter clears, URL param removed.
- Toggle the switch on, reload the page fresh (new tab, no query string): filter is restored from `localStorage`.
- Combine with a stage filter: both apply together (only orders matching both show).
- Create/seed a serviceorder with one past-ended, non-cancelled event and a non-closed stage; confirm it appears when the switch is on. Confirm a serviceorder with only a cancelled event does NOT appear even though it "has an event".

- [ ] **Step 8: Commit**

Files to stage: `resources/js/Pages/ServiceOrders/IndexPage.vue`. Suggested message: `feat(serviceorders): add switch to filter orders needing closing`. Wait for the user's go-ahead before committing, per project convention.
