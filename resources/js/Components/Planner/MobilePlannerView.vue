<template>
    <div ref="rootEl" class="flex flex-col h-full bg-white dark:bg-slate-900 text-gray-900 dark:text-slate-100">
        <!-- Sticky header: week navigation + user switcher -->
        <div class="sticky top-0 z-20 bg-white dark:bg-slate-900 border-b border-gray-200 dark:border-slate-800">
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
            <div v-if="canSeeAll" class="px-4 pb-3 flex gap-2">
                <button v-if="allPingsArray.length > 0"
                    class="flex items-center gap-1.5 rounded-md border border-gray-200 dark:border-slate-700 px-3 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-slate-800"
                    @click="mapModalOpen = true">
                    <MapIcon class="size-4 shrink-0" />
                    Monteurkaart
                </button>
                <SelectMenuComponent v-model="selectedUserId" :options="userOptions" :icon="UsersIcon"
                    class="flex-grow">
                    <template #sr-label>Monteur selecteren</template>
                </SelectMenuComponent>
            </div>
        </div>

        <!-- User profile (only when a specific user is selected) -->
        <div v-if="displayUser" class="px-4 pt-4 pb-2 bg-white dark:bg-slate-900">
            <div class="flex items-center gap-3">
                <div
                    class="size-12 rounded-full bg-gray-200 dark:bg-slate-700 ring-2 ring-white dark:ring-slate-900 flex items-center justify-center overflow-hidden text-sm font-semibold shrink-0">
                    <img v-if="displayUser.avatar" :src="displayUser.avatar" class="object-cover w-full h-full"
                        :alt="displayUser.name" />
                    <span v-else>{{ initials(displayUser.name) }}</span>
                </div>
                <div>
                    <div class="font-semibold text-base leading-tight">{{ displayUser.name }}</div>
                    <div class="text-xs text-gray-500 dark:text-slate-400">Monteur</div>
                </div>
            </div>
        </div>

        <!-- Stats bar (always visible) -->
        <div
            class="px-4 py-3 border-b border-gray-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex items-center gap-6">
            <div class="flex items-center gap-2">
                <div class="size-8 rounded-full bg-blue-50 dark:bg-blue-950/40 flex items-center justify-center">
                    <CalendarDaysIcon class="size-4 text-blue-500" />
                </div>
                <div>
                    <div class="font-semibold tabular-nums text-sm">{{ filteredEvents.length }}</div>
                    <div class="text-xs text-gray-500 dark:text-slate-400">Afspraken</div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <div class="size-8 rounded-full bg-blue-50 dark:bg-blue-950/40 flex items-center justify-center">
                    <ClockIcon class="size-4 text-blue-500" />
                </div>
                <div>
                    <div class="font-semibold tabular-nums text-sm">{{ totalHoursLabel }}</div>
                    <div class="text-xs text-gray-500 dark:text-slate-400">Gepland</div>
                </div>
            </div>
        </div>

        <!-- Timeline -->
        <div class="flex-1 overflow-y-auto">
            <div v-if="timelineEvents.length === 0"
                class="flex items-center justify-center h-40 text-sm text-gray-500 dark:text-slate-400">
                Geen afspraken deze week
            </div>

            <div v-else class="pb-24">
                <template v-for="group in groupedByDay" :key="group.dayIso">
                    <!-- Sticky day header -->
                    <div
                        class="sticky top-0 z-10 px-4 py-1.5 bg-gray-50/95 dark:bg-slate-800/95 backdrop-blur-sm border-b border-gray-200 dark:border-slate-700 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">
                        {{ dayLabel(group.dayIso) }}
                    </div>

                    <div class="pt-4 pl-3">
                        <div v-for="(ev, index) in group.events" :key="ev.id" class="flex">
                            <!-- Left: time + duration (narrowed ~20%) -->
                            <div class="w-[51px] shrink-0 flex flex-col items-end pr-2 pt-1">
                                <div class="text-sm font-semibold tabular-nums leading-none">{{ nlTime(ev.start) }}
                                </div>
                                <div class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">{{ durationLabel(ev) }}
                                </div>
                            </div>

                            <!-- Centre: dot + connecting line -->
                            <div class="relative flex flex-col items-center w-5 shrink-0">
                                <div v-if="index < group.events.length - 1"
                                    class="absolute bottom-0 w-0.5 bg-blue-300 dark:bg-blue-700"
                                    :class="index === 0 ? 'top-4' : 'top-0'"></div>
                                <div
                                    class="size-3 rounded-full bg-blue-500 ring-2 ring-white dark:ring-slate-900 mt-1 shrink-0 relative z-10">
                                </div>
                            </div>

                            <!-- Right: event card -->
                            <div class="flex-1 pl-3 pr-4 pb-5">
                                <div class="rounded-xl overflow-hidden border"
                                    :class="canEdit(ev) ? 'cursor-pointer' : ''" :style="{
                                        backgroundColor: `color-mix(in srgb, ${ev.color || '#3b82f6'} 6%, white)`,
                                        borderColor: `color-mix(in srgb, ${ev.color || '#3b82f6'} 9%, #e5e7eb)`,
                                    }" @click="handleEventTap(ev)">
                                    <div class="p-3">
                                        <!-- Title + avatars at top right -->
                                        <div class="flex items-start gap-2">
                                            <div class="font-semibold text-sm leading-tight flex-1 min-w-0">{{ ev.name
                                                || ev.title }}</div>
                                            <div class="flex items-center flex-shrink-0">
                                                <span v-if="selectedUserId === null && canSeeAll"
                                                    class="text-xs text-gray-500 dark:text-slate-400 truncate max-w-[7rem]">
                                                    {{resolveExecutingUsers(ev).map(u => u.name).join(', ')}}
                                                </span>
                                                <div v-else class="flex items-center">
                                                    <template
                                                        v-for="(u, i) in resolveExecutingUsers(ev).slice(0, MAX_AVATARS)"
                                                        :key="u.id">
                                                        <div class="size-6 rounded-full ring-2 ring-white dark:ring-slate-800 bg-gray-300 dark:bg-slate-600 flex items-center justify-center text-[10px] font-semibold overflow-hidden"
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

                                        <!-- Customer name -->
                                        <div v-if="ev.customer_name"
                                            class="text-xs text-gray-600 dark:text-slate-400 mt-1 leading-snug">
                                            {{ ev.customer_name }}
                                        </div>

                                        <!-- Address -->
                                        <div v-if="resolveAddress(ev)"
                                            class="text-xs text-gray-500 dark:text-slate-400 leading-snug">
                                            {{ resolveAddress(ev) }}
                                        </div>

                                        <!-- Description -->
                                        <div v-if="ev.description"
                                            class="text-xs text-gray-500 dark:text-slate-400 mt-1 leading-snug whitespace-pre-line">
                                            {{ ev.description }}
                                        </div>

                                        <!-- Task list -->
                                        <ul v-if="ev.task_titles && ev.task_titles.length" class="mt-1.5 space-y-0.5">
                                            <li v-for="title in ev.task_titles" :key="title"
                                                class="text-xs text-gray-500 dark:text-slate-400 flex items-center gap-1.5">
                                                <span
                                                    class="size-1 rounded-full bg-gray-400 dark:bg-slate-500 shrink-0"></span>
                                                {{ title }}
                                            </li>
                                        </ul>

                                        <!-- WB badge -->
                                        <div class="mt-2">
                                            <button v-if="ev.eventable_id"
                                                class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-slate-300 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 rounded-lg px-2 py-1 shadow-sm hover:border-gray-300 transition"
                                                @click.stop="router.visit(`/serviceorders/${ev.eventable_id}`)">
                                                <BuildingOfficeIcon class="size-3.5 shrink-0" />
                                                <span>{{ formatWbNumber(ev.eventable_id) }}</span>
                                                <ArrowTopRightOnSquareIcon class="size-3 shrink-0" />
                                            </button>
                                            <span v-else class="text-xs text-gray-400 dark:text-slate-500 italic">
                                                Eigen planning
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- FAB: create new event -->
        <button v-if="canCreate"
            class="fixed bottom-6 right-6 z-30 size-14 rounded-full bg-lavoro-green shadow-lg flex items-center justify-center hover:opacity-90 active:scale-95 transition"
            aria-label="Nieuwe afspraak aanmaken" @click="openCreate">
            <PlusIcon class="size-7 text-gray-900" />
        </button>

        <!-- Edit modal -->
        <EventEditModal v-if="modalOpen" :event-types="eventTypes" :event-statusses="eventStatusses"
            :all-customers="allCustomers" :customers-use-ajax="customersUseAjax" :all-service-orders="allServiceOrders"
            :all-users="allUsers" :initial="modalInitial" :editing-existing="editingExistingEvent" @close="closeModal"
            @saved="onSaved" />

        <!-- All-technicians map modal -->
        <ModalDialog :open="mapModalOpen" @update:open="mapModalOpen = $event" title="Monteurlocaties (laatste 8u)"
            max-width-class="sm:max-w-5xl">
            <div style="height: 80vh; width:90vw" class="relative">
                <TechnicianMapCanvas v-if="mapModalOpen" :pings="allPingsArray" :init-delay="350" />
            </div>
        </ModalDialog>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useSwipe } from '@vueuse/core'
import { usePage, router } from '@inertiajs/vue3'
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    UsersIcon,
    CalendarDaysIcon,
    ClockIcon,
    BuildingOfficeIcon,
    ArrowTopRightOnSquareIcon,
    PlusIcon,
} from '@heroicons/vue/24/outline'
import dayjs from '@/Utilities/dayjs'
import { hasPermission, initials, nlTime } from '@/Utilities/Utilities'
import { usePlannerEvents } from '@/Composables/usePlannerEvents'
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue'
import EventEditModal from '@/Components/Planner/EventEditModal.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import TechnicianMapCanvas from '@/Components/Planner/TechnicianMapCanvas.vue'
import { MapIcon } from '@lucide/vue'
const props = defineProps({
    eventTypes: { type: Array, default: () => [] },
    allCustomers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    allServiceOrders: { type: Array, default: () => [] },
    eventStatusses: { type: Array, default: () => [] },
    allUsers: { type: Array, default: () => [] },
    plannableUsers: { type: Array, default: () => [] },
    latestPings: { type: Object, default: () => ({}) },
})

const page = usePage()
const mapModalOpen = ref(false)
const allPingsArray = computed(() =>
    Object.values(props.latestPings).filter(p => p.lat != null && p.lng != null)
)

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
    const first = weekDays.value[0].date
    const last = weekDays.value[6].date
    const months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni',
        'juli', 'augustus', 'september', 'oktober', 'november', 'december']
    const sameMonth = first.getMonth() === last.getMonth()
    const sameYear = first.getFullYear() === last.getFullYear()
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

// ── Event fetching ────────────────────────────────────────────────────────────

const rootEl = ref(null)

useSwipe(rootEl, {
    threshold: 50,
    onSwipeEnd(_, direction) {
        if (direction === 'left') shiftWeek(1)
        else if (direction === 'right') shiftWeek(-1)
    },
})

const { events, fetchEvents, startPolling, stopPolling, resetFingerprint } = usePlannerEvents(
    weekStart,
    () => modalOpen.value || !rootEl.value?.offsetParent,
)

onMounted(() => { fetchEvents(); startPolling() })
onUnmounted(stopPolling)
watch(weekStart, () => { resetFingerprint(); fetchEvents() })

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

// ── User card & stats ─────────────────────────────────────────────────────────

const displayUser = computed(() => {
    if (selectedUserId.value !== null) {
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
    filteredEvents.value.reduce((sum, ev) => {
        const userBreaktime = ev.executing_users?.find(u => u.id === selectedUserId.value)?.breaktime ?? 0
        return sum + Math.max(0, (ev.end - ev.start) / 60000 - userBreaktime)
    }, 0)
)

const totalHoursLabel = computed(() => {
    const h = Math.floor(totalMinutes.value / 60)
    const m = Math.round(totalMinutes.value % 60)
    return `${h}u ${String(m).padStart(2, '0')}m`
})

// ── Timeline helpers ──────────────────────────────────────────────────────────

const MAX_AVATARS = 3

const timelineEvents = computed(() =>
    [...filteredEvents.value].sort((a, b) => a.start - b.start)
)

const groupedByDay = computed(() => {
    const groups = []
    let currentIso = null
    let currentGroup = null
    for (const ev of timelineEvents.value) {
        const iso = dayjs(ev.start).format('YYYY-MM-DD')
        if (iso !== currentIso) {
            currentIso = iso
            currentGroup = { dayIso: iso, events: [] }
            groups.push(currentGroup)
        }
        currentGroup.events.push(ev)
    }
    return groups
})

const DAY_NAMES = ['Zondag', 'Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag']

function dayLabel(dayIso) {
    return DAY_NAMES[dayjs(dayIso).day()]
}

function durationLabel(ev) {
    const userBreaktime = selectedUserId.value
        ? ev.executing_users?.find(u => u.id === selectedUserId.value)?.breaktime ?? 0
        : 0
    const mins = Math.round(Math.max(0, (ev.end - ev.start) / 60000 - userBreaktime))
    const h = Math.floor(mins / 60)
    const m = mins % 60
    if (h > 0 && m > 0) return `${h}u ${m}m`
    if (h > 0) return `${h}u`
    return `${m}m`
}

function formatWbNumber(eventableId) {
    return `WB-${String(eventableId).padStart(4, '0')}`
}

function resolveAddress(ev) {
    if (!ev.eventable_id) return null
    const so = props.allServiceOrders.find(s => s.id === ev.eventable_id)
    if (!so) return null
    const customer = so.customer ?? null
    if (!customer) return null
    const parts = [customer.address, customer.city].filter(Boolean)
    return parts.length ? parts.join(', ') : null
}

function resolveExecutingUsers(ev) {
    return ev.executing_users.map(u => {
        const plannable = props.plannableUsers.find(p => p.id === u.id)
        return { id: u.id, name: u.name, avatar: plannable?.avatar ?? null }
    })
}

// ── Tap-to-edit / create ─────────────────────────────────────────────────────

const authUserId = computed(() => page.props.auth.user?.id ?? null)

const canCreate = computed(() => hasPermission('event.create'))

const modalOpen = ref(false)
const editingExistingEvent = ref(false)
const modalInitial = ref(null)

function canEdit(ev) {
    if (hasPermission('event.update_others')) return true
    if (hasPermission('event.update') && ev.executing_user_ids.includes(authUserId.value)) return true
    return false
}

function handleEventTap(ev) {
    if (!canEdit(ev)) return
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
    }
    editingExistingEvent.value = true
    modalOpen.value = true
}

function openCreate() {
    const start = dayjs().add(1, 'hour').startOf('hour').toDate()
    const end = dayjs(start).add(1, 'hour').toDate()
    modalInitial.value = {
        id: null,
        event_type_id: null,
        name: null,
        description: null,
        status: null,
        start,
        end,
        eventable_type: null,
        eventable_id: null,
        customer_id: null,
        customer_name: null,
        executing_user_ids: authUserId.value ? [authUserId.value] : [],
    }
    editingExistingEvent.value = false
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
</script>
