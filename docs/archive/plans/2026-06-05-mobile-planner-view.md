# Mobile Planner View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a full-width mobile timeline view that shows a week of events as a vertical chronological list, with week navigation, a permission-gated user switcher, a user stats card, and tap-to-edit — shown only on screens narrower than `md`, while the existing desktop planner widget remains unchanged.

**Architecture:** A new standalone Vue 3 component `MobilePlannerView.vue` fetches events from the existing `/api/events` endpoint, manages its own `weekStart` ref, and filters events client-side. `IndexPage.vue` renders both views and uses Tailwind `md:hidden` / `hidden md:grid` to swap between them. No backend changes.

**Tech Stack:** Vue 3 Composition API, Inertia.js (`usePage`, `router`), axios, dayjs, Heroicons v2 outline, existing `SelectMenuComponent`, existing `EventEditModal`.

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `resources/js/Components/Planner/MobilePlannerView.vue` | Create | Week nav, user switcher, stats card, timeline, tap-to-edit |
| `resources/js/Pages/Planner/IndexPage.vue` | Modify | Show mobile view on `<md`, desktop grid on `md+` |

---

### Task 1: Scaffold MobilePlannerView — week navigation

**Files:**
- Create: `resources/js/Components/Planner/MobilePlannerView.vue`

- [ ] **Step 1: Create the file with all imports, props, and working week navigation**

Create `resources/js/Components/Planner/MobilePlannerView.vue`:

```vue
<template>
    <div class="flex flex-col bg-white dark:bg-slate-900 text-gray-900 dark:text-slate-100 min-h-screen">
        <!-- Sticky header: week navigation + user switcher (added in Task 3) -->
        <div class="sticky top-0 z-20 bg-white dark:bg-slate-900 border-b border-gray-200 dark:border-slate-800">
            <div class="flex items-center justify-between px-4 py-3">
                <button
                    class="p-2 rounded-md hover:bg-gray-100 dark:hover:bg-slate-800"
                    aria-label="Vorige week"
                    @click="shiftWeek(-1)">
                    <ChevronLeftIcon class="size-5" />
                </button>
                <span class="font-semibold text-sm">{{ weekTitle }}</span>
                <button
                    class="p-2 rounded-md hover:bg-gray-100 dark:hover:bg-slate-800"
                    aria-label="Volgende week"
                    @click="shiftWeek(1)">
                    <ChevronRightIcon class="size-5" />
                </button>
            </div>
            <!-- User switcher placeholder — replaced in Task 3 -->
        </div>

        <!-- Body placeholder — replaced in Tasks 4 and 5 -->
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import axios from 'axios'
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    CalendarDaysIcon,
    ClockIcon,
    CalendarIcon,
    BuildingOfficeIcon,
    ArrowTopRightOnSquareIcon,
    UsersIcon,
} from '@heroicons/vue/24/outline'
import dayjs from '@/Utilities/dayjs'
import {
    hasPermission,
    initials,
    nlTime,
    formatUtcDatetime,
} from '@/Utilities/Utilities'
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue'
import EventEditModal from '@/Components/Planner/EventEditModal.vue'

const props = defineProps({
    eventTypes:       { type: Array, default: () => [] },
    allCustomers:     { type: Array, default: () => [] },
    allServiceOrders: { type: Array, default: () => [] },
    eventStatusses:   { type: Array, default: () => [] },
    allUsers:         { type: Array, default: () => [] },
    plannableUsers:   { type: Array, default: () => [] },
})

const page = usePage()

// ── Week navigation ───────────────────────────────────────────────────────────

function startOfWeek(date) {
    return dayjs(date).startOf('isoWeek').toDate()
}

const weekStart = ref(startOfWeek(new Date()))

const weekDays = computed(() => {
    const start = dayjs(weekStart.value)
    return Array.from({ length: 7 }, (_, i) => {
        const d = start.add(i, 'day')
        return { date: d.toDate(), iso: d.format('YYYY-MM-DD') }
    })
})

const weekTitle = computed(() => {
    const first  = weekDays.value[0].date
    const last   = weekDays.value[6].date
    const months = ['januari','februari','maart','april','mei','juni',
                    'juli','augustus','september','oktober','november','december']
    const sameMonth = first.getMonth() === last.getMonth()
    const sameYear  = first.getFullYear() === last.getFullYear()
    if (sameMonth && sameYear) {
        return `${first.getDate()} – ${last.getDate()} ${months[first.getMonth()]} ${first.getFullYear()}`
    }
    if (sameYear) {
        return `${first.getDate()} ${months[first.getMonth()]} – ${last.getDate()} ${months[last.getMonth()]} ${first.getFullYear()}`
    }
    return `${first.getDate()} ${months[first.getMonth()]} ${first.getFullYear()} – ${last.getDate()} ${months[last.getMonth()]} ${last.getFullYear()}`
})

function shiftWeek(direction) {
    weekStart.value = dayjs(weekStart.value).add(direction * 7, 'day').toDate()
}
</script>
```

---

### Task 2: Event fetching

**Files:**
- Modify: `resources/js/Components/Planner/MobilePlannerView.vue`

- [ ] **Step 1: Append event fetching section to `<script setup>` (after the week navigation block)**

```js
// ── Event fetching ────────────────────────────────────────────────────────────

const events = ref([])

async function fetchEvents() {
    try {
        await axios.get('sanctum/csrf-cookie')
        const startParam = formatUtcDatetime(weekStart.value)
        const endParam   = formatUtcDatetime(dayjs(weekStart.value).add(7, 'day').toDate())
        const response   = await axios.get(
            `/api/events?start=${encodeURIComponent(startParam)}&end=${encodeURIComponent(endParam)}`
        )
        if (response.status !== 200) return
        events.value = response.data.map(ev => {
            const customer_id = ev.service_orders?.[0]?.customer_id ?? null
            const customer    = customer_id
                ? props.allCustomers.find(c => c.id === customer_id)
                : null
            return {
                id:                  ev.id,
                title:               ev.event_type?.name || ev.name || 'Afspraak',
                name:                ev.name,
                description:         ev.description,
                status:              ev.status,
                color:               ev.event_type?.color || '#3b82f6',
                event_type_id:       ev.event_type?.id,
                start:               new Date(ev.start),
                end:                 new Date(ev.end),
                executing_user_ids:  (ev.executing_users || []).map(u => u.id),
                executing_users:     ev.executing_users || [],
                eventable_id:        ev.service_orders?.[0]?.id ?? null,
                eventable_type:      '\\App\\Models\\ServiceOrder',
                customer_id,
                customer_name:       customer?.name || null,
            }
        })
    } catch (e) {
        console.error('Failed to fetch events for mobile planner', e)
    }
}

onMounted(fetchEvents)
watch(weekStart, fetchEvents)
```

---

### Task 3: User switcher and filtered events

**Files:**
- Modify: `resources/js/Components/Planner/MobilePlannerView.vue`

- [ ] **Step 1: Append user switcher state to `<script setup>` (after the event fetching block)**

```js
// ── User switcher ─────────────────────────────────────────────────────────────

const canSeeAll = computed(() => hasPermission('event.see_all'))

const selectedUserId = ref(
    hasPermission('event.see_all') ? null : (page.props.auth.user?.id ?? null)
)

const userOptions = computed(() => [
    { value: null, title: 'Alle monteurs', shortTitle: 'Allen' },
    ...props.plannableUsers.map(u => ({ value: u.id, title: u.name, shortTitle: u.name })),
])

const filteredEvents = computed(() => {
    if (selectedUserId.value === null) return events.value
    return events.value.filter(ev => ev.executing_user_ids.includes(selectedUserId.value))
})
```

- [ ] **Step 2: Replace `<!-- User switcher placeholder — replaced in Task 3 -->` in the template**

```html
<!-- User switcher (event.see_all only) -->
<div v-if="canSeeAll" class="px-4 pb-3">
    <SelectMenuComponent v-model="selectedUserId" :options="userOptions" :icon="UsersIcon">
        <template #sr-label>Monteur selecteren</template>
    </SelectMenuComponent>
</div>
```

---

### Task 4: User card and stats

**Files:**
- Modify: `resources/js/Components/Planner/MobilePlannerView.vue`

- [ ] **Step 1: Append user card computeds to `<script setup>` (after the user switcher block)**

```js
// ── User card & stats ─────────────────────────────────────────────────────────

const displayUser = computed(() => {
    if (selectedUserId.value) {
        return props.plannableUsers.find(u => u.id === selectedUserId.value)
            ?? props.allUsers.find(u => u.id === selectedUserId.value)
            ?? null
    }
    if (!canSeeAll.value) {
        return page.props.auth.user ?? null
    }
    return null // "Alle monteurs" — no card
})

const totalMinutes = computed(() =>
    filteredEvents.value.reduce(
        (sum, ev) => sum + Math.max(0, (ev.end - ev.start) / 60000),
        0
    )
)

const totalHoursLabel = computed(() => {
    const h = Math.floor(totalMinutes.value / 60)
    const m = Math.round(totalMinutes.value % 60)
    return `${h}u ${String(m).padStart(2, '0')}m`
})
```

- [ ] **Step 2: Replace `<!-- Body placeholder — replaced in Tasks 4 and 5 -->` in the template with the user card block**

```html
<!-- User card -->
<div v-if="displayUser"
    class="px-4 py-4 border-b border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-900">
    <div class="flex items-center gap-3">
        <div class="size-12 rounded-full bg-gray-200 dark:bg-slate-700 ring-2 ring-white dark:ring-slate-900 flex items-center justify-center overflow-hidden text-sm font-semibold shrink-0">
            <img v-if="displayUser.avatar" :src="displayUser.avatar"
                class="object-cover w-full h-full" :alt="displayUser.name" />
            <span v-else>{{ initials(displayUser.name) }}</span>
        </div>
        <div>
            <div class="font-semibold text-base leading-tight">{{ displayUser.name }}</div>
            <div class="text-xs text-gray-500 dark:text-slate-400">Monteur</div>
        </div>
    </div>
    <div class="mt-3 flex items-center gap-6 text-sm">
        <div class="flex items-center gap-2">
            <div class="size-8 rounded-full bg-blue-50 dark:bg-blue-950/40 flex items-center justify-center">
                <CalendarDaysIcon class="size-4 text-blue-500" />
            </div>
            <div>
                <div class="font-semibold tabular-nums">{{ filteredEvents.length }}</div>
                <div class="text-xs text-gray-500 dark:text-slate-400">Afspraken</div>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <div class="size-8 rounded-full bg-blue-50 dark:bg-blue-950/40 flex items-center justify-center">
                <ClockIcon class="size-4 text-blue-500" />
            </div>
            <div>
                <div class="font-semibold tabular-nums">{{ totalHoursLabel }}</div>
                <div class="text-xs text-gray-500 dark:text-slate-400">Gepland</div>
            </div>
        </div>
    </div>
</div>

<!-- Timeline — added in Task 5 -->
```

---

### Task 5: Timeline helpers, tap-to-edit logic, and the full timeline template

**Files:**
- Modify: `resources/js/Components/Planner/MobilePlannerView.vue`

All script code goes in first so every function referenced by the template already exists when the template is added.

- [ ] **Step 1: Append timeline helpers and tap-to-edit functions to `<script setup>` (after the user card block)**

```js
// ── Timeline helpers ──────────────────────────────────────────────────────────

const MAX_AVATARS = 3

const timelineEvents = computed(() =>
    [...filteredEvents.value].sort((a, b) => a.start - b.start)
)

function durationLabel(ev) {
    const mins = Math.round((ev.end - ev.start) / 60000)
    const h    = Math.floor(mins / 60)
    const m    = mins % 60
    if (h > 0 && m > 0) return `${h}u ${m}m`
    if (h > 0) return `${h}u`
    return `${m}m`
}

function formatWbNumber(eventableId, startDate) {
    const year = new Date(startDate).getFullYear()
    return `WB-${year}-${String(eventableId).padStart(4, '0')}`
}

function resolveAddress(ev) {
    if (!ev.eventable_id) return null
    const so = props.allServiceOrders.find(s => s.id === ev.eventable_id)
    if (!so) return null
    const customer = props.allCustomers.find(c => c.id === so.customer_id)
    if (!customer) return null
    const parts = [customer.street, customer.city].filter(Boolean)
    return parts.length ? parts.join(', ') : null
}

function resolveExecutingUsers(ev) {
    return ev.executing_users.map(u => {
        const plannable = props.plannableUsers.find(p => p.id === u.id)
        return { id: u.id, name: u.name, avatar: plannable?.avatar ?? null }
    })
}

// ── Tap-to-edit ───────────────────────────────────────────────────────────────

const authUserId = computed(() => page.props.auth.user?.id ?? null)

const modalOpen            = ref(false)
const editingExistingEvent = ref(false)
const modalInitial         = ref(null)

function canEdit(ev) {
    if (hasPermission('event.update_others')) return true
    if (hasPermission('event.update') && ev.executing_user_ids.includes(authUserId.value)) return true
    return false
}

function handleEventTap(ev) {
    if (!canEdit(ev)) return
    modalInitial.value = {
        id:                  ev.id,
        event_type_id:       ev.event_type_id,
        name:                ev.name,
        description:         ev.description,
        status:              ev.status,
        start:               ev.start,
        end:                 ev.end,
        eventable_type:      ev.eventable_type,
        eventable_id:        ev.eventable_id,
        customer_id:         ev.customer_id,
        executing_user_ids:  [...ev.executing_user_ids],
    }
    editingExistingEvent.value = true
    modalOpen.value = true
}

function closeModal() {
    modalOpen.value = false
    modalInitial.value = null
}

function onSaved() {
    closeModal()
    fetchEvents()
}
```

- [ ] **Step 2: Replace `<!-- Timeline — added in Task 5 -->` in the template with the full timeline and modal**

```html
<!-- Timeline -->
<div class="flex-1 overflow-y-auto">
    <div v-if="timelineEvents.length === 0"
        class="flex items-center justify-center h-40 text-sm text-gray-500 dark:text-slate-400">
        Geen afspraken deze week
    </div>

    <div v-else class="py-4">
        <div v-for="(ev, index) in timelineEvents" :key="ev.id" class="flex">
            <!-- Left: time + duration -->
            <div class="w-16 shrink-0 flex flex-col items-end pr-3 pt-1">
                <div class="text-sm font-semibold tabular-nums leading-none">{{ nlTime(ev.start) }}</div>
                <div class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">{{ durationLabel(ev) }}</div>
            </div>

            <!-- Centre: dot + connecting line -->
            <div class="flex flex-col items-center w-5 shrink-0">
                <div class="size-3 rounded-full bg-blue-500 ring-2 ring-white dark:ring-slate-900 mt-1.5 shrink-0 z-10"></div>
                <div v-if="index < timelineEvents.length - 1"
                    class="w-px flex-1 bg-gray-200 dark:bg-slate-700 mt-1"></div>
            </div>

            <!-- Right: event card -->
            <div class="flex-1 pl-3 pb-5">
                <div
                    class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 shadow-sm overflow-hidden"
                    :class="canEdit(ev) ? 'cursor-pointer active:bg-gray-50 dark:active:bg-slate-700/60' : ''"
                    @click="handleEventTap(ev)">
                    <div class="p-3 border-l-4" :style="{ borderLeftColor: ev.color || '#3b82f6' }">
                        <!-- Title + icon -->
                        <div class="flex items-start justify-between gap-2">
                            <div class="font-semibold text-sm leading-tight">{{ ev.name || ev.title }}</div>
                            <CalendarIcon class="size-4 shrink-0 text-gray-400 mt-0.5" />
                        </div>

                        <!-- Customer name -->
                        <div v-if="ev.customer_name"
                            class="text-xs text-gray-500 dark:text-slate-400 mt-1 leading-snug">
                            {{ ev.customer_name }}
                        </div>

                        <!-- Address -->
                        <div v-if="resolveAddress(ev)"
                            class="text-xs text-gray-500 dark:text-slate-400 leading-snug">
                            {{ resolveAddress(ev) }}
                        </div>

                        <!-- WB badge + executing users -->
                        <div class="mt-2 flex items-center justify-between gap-2 flex-wrap">
                            <button v-if="ev.eventable_id"
                                class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 transition"
                                @click.stop="router.visit(`/serviceorders/${ev.eventable_id}`)">
                                <BuildingOfficeIcon class="size-3.5 shrink-0" />
                                <span>{{ formatWbNumber(ev.eventable_id, ev.start) }}</span>
                                <ArrowTopRightOnSquareIcon class="size-3 shrink-0" />
                            </button>
                            <span v-else class="text-xs text-gray-400 dark:text-slate-500 italic">
                                Eigen planning
                            </span>

                            <!-- "Alle monteurs" mode: comma-separated names -->
                            <span v-if="selectedUserId === null && canSeeAll"
                                class="text-xs text-gray-500 dark:text-slate-400 truncate max-w-[10rem]">
                                {{ resolveExecutingUsers(ev).map(u => u.name).join(', ') }}
                            </span>

                            <!-- Specific user mode: avatar stack -->
                            <div v-else class="flex items-center">
                                <template v-for="(u, i) in resolveExecutingUsers(ev).slice(0, MAX_AVATARS)" :key="u.id">
                                    <div
                                        class="size-6 rounded-full ring-2 ring-white dark:ring-slate-800 bg-gray-300 dark:bg-slate-600 flex items-center justify-center text-[10px] font-semibold overflow-hidden"
                                        :style="{ marginLeft: i > 0 ? '-0.375rem' : '0' }">
                                        <img v-if="u.avatar" :src="u.avatar"
                                            class="w-full h-full object-cover" :alt="u.name" />
                                        <span v-else>{{ initials(u.name) }}</span>
                                    </div>
                                </template>
                                <div v-if="resolveExecutingUsers(ev).length > MAX_AVATARS"
                                    class="size-6 rounded-full ring-2 ring-white dark:ring-slate-800 bg-gray-200 dark:bg-slate-700 flex items-center justify-center text-[10px] font-semibold -ml-1.5">
                                    +{{ resolveExecutingUsers(ev).length - MAX_AVATARS }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit modal -->
<EventEditModal
    v-if="modalOpen"
    :event-types="eventTypes"
    :event-statusses="eventStatusses"
    :all-customers="allCustomers"
    :all-service-orders="allServiceOrders"
    :all-users="allUsers"
    :initial="modalInitial"
    :editing-existing="editingExistingEvent"
    @close="closeModal"
    @saved="onSaved" />
```

---

### Task 6: Wire MobilePlannerView into IndexPage

**Files:**
- Modify: `resources/js/Pages/Planner/IndexPage.vue`

- [ ] **Step 1: Add the import after the existing imports in `<script setup>`**

```js
import MobilePlannerView from '@/Components/Planner/MobilePlannerView.vue'
```

- [ ] **Step 2: Replace the full `<template>` content**

```vue
<template>
    <!-- Mobile (below md) -->
    <div class="md:hidden">
        <MobilePlannerView
            :event-types="eventTypes"
            :all-customers="allCustomers"
            :all-service-orders="allServiceOrders"
            :event-statusses="eventStatusses"
            :all-users="allUsers"
            :plannable-users="plannableUsers" />
    </div>

    <!-- Desktop (md and up) -->
    <div class="hidden md:grid grid-cols-12 gap-x-3 p-3">
        <div class="col-span-10">
            <BoxComponent padding="p-0">
                <ResourcePlannerWidget
                    :event-types="eventTypes"
                    :all-customers="allCustomers"
                    :all-service-orders="allServiceOrders"
                    :event-statusses="eventStatusses"
                    :all-users="allUsers"
                    :plannable-users="plannableUsers"
                    :projects="projects"
                    @service-order-planned="onServiceOrderPlanned"
                    @service-order-unplanned="onServiceOrderUnplanned" />
            </BoxComponent>
        </div>
        <div class="col-span-2">
            <BoxComponent padding="p-0">
                <UnplannedServiceOrdersWidget :service-orders="unplanned" />
            </BoxComponent>
        </div>
    </div>
</template>
```

> **Note on customer address fields:** `resolveAddress` uses `customer.street` and `customer.city`. Verify the actual field names against the `Customer` Eloquent model before finishing Task 5 — adjust if the model uses different names (e.g. `address`, `postal_code`).
