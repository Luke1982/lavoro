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
                <button v-if="canShiftForward" class="p-2 rounded-md hover:bg-gray-100 dark:hover:bg-slate-800"
                    aria-label="Volgende week" @click="shiftWeek(1)">
                    <ChevronRightIcon class="size-5" />
                </button>
                <span v-else class="w-10 shrink-0" />
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
                <button v-if="selectedUserId === null"
                    class="flex items-center rounded-md border border-gray-200 dark:border-slate-700 px-3 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-slate-800"
                    :class="laneView ? 'bg-gray-100 dark:bg-slate-800' : ''"
                    :aria-pressed="laneView" :aria-label="laneView ? 'Lijstweergave' : 'Baanweergave'"
                    @click="laneView = !laneView">
                    <List v-if="laneView" class="size-4 shrink-0" />
                    <Columns3 v-else class="size-4 shrink-0" />
                </button>
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
            <div v-if="unclosedCount > 0" class="flex items-center gap-2">
                <div class="size-8 rounded-full bg-amber-50 dark:bg-amber-950/40 flex items-center justify-center">
                    <TriangleAlert class="size-4 text-amber-500" />
                </div>
                <div>
                    <div class="font-semibold tabular-nums text-sm">{{ unclosedCount }}</div>
                    <div class="text-xs text-gray-500 dark:text-slate-400">Niet afgerond</div>
                </div>
            </div>
        </div>

        <!-- Timeline -->
        <PlannerLoadingOverlay :loading="eventsLoading">
            <MobileLaneView v-if="showLaneView" :plannable-users="plannableUsers" :week-days="weekDays"
                :events="events" :day-start-hour="WORKDAY_START_HOUR" :day-end-hour="WORKDAY_END_HOUR"
                :allow-override-unavailability="allowOverrideUnavailability" :can-create="canCreate"
                :can-go-forward="canShiftForward" @slot-tap="handleSlotTap" @event-tap="handleEventTap"
                @flip-week="shiftWeek" />

            <div v-else class="relative h-full">
                <div ref="listScrollEl" class="h-full overflow-y-auto" @touchstart.passive="onListTouchStart"
                    @touchmove.passive="onListTouchMove" @touchend.passive="onListTouchEnd"
                    @touchcancel.passive="onListTouchEnd">
                    <div v-if="!eventsLoading && timelineEvents.length === 0 && !showsGaps"
                        class="flex items-center justify-center h-40 text-sm text-gray-500 dark:text-slate-400">
                        Geen afspraken deze week
                    </div>

                    <div v-else class="pb-24">
                    <template v-for="group in groupedByDay" :key="group.dayIso">
                        <!-- Sticky day header -->
                        <div
                            class="sticky top-0 z-10 px-4 py-1.5 bg-gray-50/95 dark:bg-slate-800/95 backdrop-blur-sm border-b border-gray-200 dark:border-slate-700 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">
                            {{ dayLabel(group.dayIso) }}
                            <span v-if="showsGaps" class="font-normal normal-case">{{ dayjs(group.dayIso).format('D MMM')
                                }}</span>
                        </div>

                        <div class="pt-4 pl-3">
                            <div v-for="(item, index) in group.items" :key="itemKey(item)" class="flex">
                                <!-- Left: time + duration (narrowed ~20%) -->
                                <div class="w-[51px] shrink-0 flex flex-col items-end pr-2 pt-1">
                                    <div class="text-sm tabular-nums leading-none"
                                        :class="item.kind === 'event' ? 'font-semibold' : 'font-medium text-gray-400 dark:text-slate-500'">
                                        {{ formatTimeLabel(item.startMin) }}
                                    </div>
                                    <div class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                                        {{ formatDurationLabel(item.durationMin) }}
                                    </div>
                                </div>

                                <!-- Centre: dot + connecting line -->
                                <div class="relative flex flex-col items-center w-5 shrink-0">
                                    <div v-if="index < group.items.length - 1" class="absolute bottom-0 w-0.5"
                                        :class="[
                                            index === 0 ? 'top-4' : 'top-0',
                                            item.kind === 'event' ? 'bg-blue-300 dark:bg-blue-700' : 'bg-gray-200 dark:bg-slate-700',
                                        ]"></div>
                                    <div class="size-3 rounded-full ring-2 ring-white dark:ring-slate-900 mt-1 shrink-0 relative z-10"
                                        :class="item.kind === 'event'
                                            ? 'bg-blue-500'
                                            : 'border-2 border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900'">
                                    </div>
                                </div>

                                <!-- Right: free or blocked room -->
                                <div v-if="item.kind !== 'event'" class="flex-1 pl-3 pr-4 pb-5">
                                    <button type="button" class="w-full rounded-xl px-3 py-2 text-left"
                                        :class="[
                                            item.kind === 'free'
                                                ? 'border border-dashed border-emerald-300 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-950/20'
                                                : 'border border-gray-200 dark:border-slate-700',
                                            gapTappable(item) ? 'cursor-pointer' : 'cursor-default',
                                        ]"
                                        :style="item.kind === 'blocked' ? { background: UNAVAILABLE_PATTERN } : {}"
                                        :disabled="!gapTappable(item)"
                                        @click="handleSlotTap({ dayIso: group.dayIso, userId: selectedUserId, ...item })">
                                        <div class="flex items-center gap-1.5">
                                            <PlusIcon v-if="item.kind === 'free' && gapTappable(item)"
                                                class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-500" />
                                            <span class="text-sm font-medium"
                                                :class="item.kind === 'free'
                                                    ? 'text-emerald-700 dark:text-emerald-400'
                                                    : 'text-gray-600 dark:text-slate-300'">
                                                {{ item.kind === 'free'
                                                    ? formatDurationLabel(item.durationMin) + ' vrij'
                                                    : (item.label || 'Niet beschikbaar') }}
                                            </span>
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                                            {{ gapCaption(item) }}
                                        </div>
                                        <div v-if="freeUsersLabel(item)"
                                            class="text-xs text-emerald-700/80 dark:text-emerald-400/80 mt-0.5 leading-snug">
                                            {{ freeUsersLabel(item) }}
                                        </div>
                                    </button>
                                </div>

                                <!-- Right: event card -->
                                <div v-else class="flex-1 pl-3 pr-4 pb-5">
                                    <MobileEventCard :event="item.event" :selected-user-id="selectedUserId"
                                        :relevant-user-id="relevantUserId" :can-see-all="canSeeAll"
                                        :can-edit="canEdit(item.event)" :plannable-users="plannableUsers"
                                        :user-roles="userRoles" @tap="handleEventTap"
                                        @feedback="feedback.openFeedback" @changed="fetchEvents" />
                                </div>
                            </div>
                        </div>
                        </template>
                    </div>
                </div>
                <WeekFlipIndicator :pull="listPull" :armed="listPullArmed" :progress="listPullProgress" />
            </div>
        </PlannerLoadingOverlay>

        <!-- FAB: create new event -->
        <button v-if="canCreate"
            class="fixed bottom-6 right-6 z-30 size-14 rounded-full bg-lavoro-green shadow-lg flex items-center justify-center hover:opacity-90 active:scale-95 transition"
            aria-label="Nieuwe afspraak aanmaken" @click="openCreate">
            <PlusIcon class="size-7 text-gray-900" />
        </button>

        <!-- Edit modal -->
        <EventEditModal v-if="modalOpen" :event-types="eventTypes" :event-statusses="eventStatusses"
            :all-customers="allCustomers" :customers-use-ajax="customersUseAjax" :all-service-orders="allServiceOrders"
            :all-users="allUsers" :user-roles="userRoles" :initial="modalInitial"
            :editing-existing="editingExistingEvent" @close="closeModal" @saved="onSaved" />

        <UnavailabilityOverrideDialog :open="unavailOverrideDialog.open" :users="unavailOverrideDialog.users"
            @confirm="onOverrideConfirm" @cancel="onOverrideCancel" />

        <!-- All-technicians map modal -->
        <ModalDialog :open="mapModalOpen" @update:open="mapModalOpen = $event" title="Monteurlocaties (laatste 8u)"
            max-width-class="sm:max-w-5xl">
            <div style="height: 80vh; width:90vw" class="relative">
                <TechnicianMapCanvas v-if="mapModalOpen" :pings="allPingsArray" :init-delay="350" />
            </div>
        </ModalDialog>

        <ModalDialog v-model:open="feedback.open.value" :title="feedbackTitle" max-width-class="sm:max-w-2xl">
            <div v-if="feedback.activeEvent.value" class="space-y-6">
                <RemarksComponent :comments="feedback.remarks.value" :remarkable-type="'App\\Models\\Event'"
                    :remarkable-id="feedback.activeEvent.value.id" :api-mode="true"
                    @created="feedback.onRemarkCreated" @deleted="feedback.onRemarkDeleted" />
                <ImageUploadComponent :existing="feedback.images.value" :imageable-type="'App\\Models\\Event'"
                    :imageable-id="feedback.activeEvent.value.id" :api-mode="true"
                    :can-manage="hasPermission('event.provide_feedback')"
                    @images-uploaded="feedback.onImagesUploaded" @image-deleted="feedback.onImageDeleted" />
            </div>
        </ModalDialog>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    UsersIcon,
    CalendarDaysIcon,
    ClockIcon,
    PlusIcon,
} from '@heroicons/vue/24/outline'
import dayjs from '@/Utilities/dayjs'
import { hasPermission, initials, nlDayName, formatLocalDateAsISO } from '@/Utilities/Utilities'
import {
    UNAVAILABLE_PATTERN,
    completionStatusFor,
    formatDurationLabel,
    formatTimeLabel,
} from '@/Utilities/plannerOverlaps'
import { usePlannerEvents } from '@/Composables/usePlannerEvents'
import {
    daySegmentsAcrossUsers,
    daySegmentsFor,
    eventMinutesFor,
    eventsOnDay,
} from '@/Composables/usePlannerGaps'
import { blockedUsersAt, useUnavailabilityOverride } from '@/Composables/useUnavailability'
import { usePullToFlip } from '@/Composables/usePullToFlip'
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue'
import EventEditModal from '@/Components/Planner/EventEditModal.vue'
import MobileEventCard from '@/Components/Planner/MobileEventCard.vue'
import MobileLaneView from '@/Components/Planner/MobileLaneView.vue'
import WeekFlipIndicator from '@/Components/Planner/WeekFlipIndicator.vue'
import UnavailabilityOverrideDialog from '@/Components/Planner/UnavailabilityOverrideDialog.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import RemarksComponent from '@/Components/RemarksComponent.vue'
import ImageUploadComponent from '@/Components/ImageUploadComponent.vue'
import TechnicianMapCanvas from '@/Components/Planner/TechnicianMapCanvas.vue'
import PlannerLoadingOverlay from '@/Components/Planner/PlannerLoadingOverlay.vue'
import { useEventFeedback } from '@/Composables/useEventFeedback'
import { MapIcon, TriangleAlert, Columns3, List } from '@lucide/vue'
const props = defineProps({
    eventTypes: { type: Array, default: () => [] },
    allCustomers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    allServiceOrders: { type: Array, default: () => [] },
    eventStatusses: { type: Array, default: () => [] },
    allUsers: { type: Array, default: () => [] },
    plannableUsers: { type: Array, default: () => [] },
    userRoles: { type: Array, default: () => [] },
    latestPings: { type: Object, default: () => ({}) },
    allowOverrideUnavailability: { type: Boolean, default: false },
})

// Mirrors the desktop grid's defaults; mobile has no settings panel to shift them.
const WORKDAY_START_HOUR = 7
const WORKDAY_END_HOUR = 18

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

const canShiftForward = computed(() =>
    hasPermission('event.see_beyond_current_week') ||
    dayjs(weekStart.value).startOf('isoWeek').isBefore(dayjs().add(7, 'day').startOf('isoWeek'), 'day')
)

function shiftWeek(direction) {
    if (direction === 1 && !canShiftForward.value) return
    weekStart.value = dayjs(weekStart.value).add(direction * 7, 'day').toDate()
}

// The stacked list scrolls vertically, so a horizontal pull past its edge is
// free to change week with the same deliberate gesture the lane view uses.
const listScrollEl = ref(null)
const {
    pull: listPull,
    pullArmed: listPullArmed,
    pullProgress: listPullProgress,
    onTouchStart: onListTouchStart,
    onTouchMove: onListTouchMove,
    onTouchEnd: onListTouchEnd,
} = usePullToFlip(listScrollEl, {
    canGoForward: () => canShiftForward.value,
    onFlip: (direction) => shiftWeek(direction),
})

// ── Event fetching ────────────────────────────────────────────────────────────

const rootEl = ref(null)

const dayCount = ref(7)
const { events, eventsLoading, fetchEvents, startPolling, stopPolling, resetFingerprint } = usePlannerEvents(
    weekStart,
    dayCount,
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

const timelineEvents = computed(() =>
    [...filteredEvents.value].sort((a, b) => a.start - b.start)
)

// Planners get the room between appointments spelled out; mechanics keep the
// plain stack of what they have to do.
const showsGaps = computed(() => canSeeAll.value)

// With a mechanic picked the room is theirs. Across the team, room that only
// some of them have is still worth showing, so those stretches name who is free.
function segmentsForDay(dayIso) {
    const shared = {
        events: events.value,
        plannableUsers: props.plannableUsers,
        dayIso,
        dayStartHour: WORKDAY_START_HOUR,
        dayEndHour: WORKDAY_END_HOUR,
    }
    return selectedUserId.value === null
        ? daySegmentsAcrossUsers(shared)
        : daySegmentsFor({ ...shared, userId: selectedUserId.value })
}

function eventItem(ev, dayIso) {
    return {
        kind: 'event',
        event: ev,
        startMin: eventMinutesFor(ev, selectedUserId.value, dayIso).startMin,
        durationMin: eventDurationMinutes(ev),
    }
}

const groupedByDay = computed(() => {
    if (!showsGaps.value) {
        const groups = []
        for (const ev of timelineEvents.value) {
            const dayIso = formatLocalDateAsISO(ev.start)
            if (groups.at(-1)?.dayIso !== dayIso) groups.push({ dayIso, items: [] })
            groups.at(-1).items.push(eventItem(ev, dayIso))
        }
        return groups
    }

    return weekDays.value
        .map(day => {
            const items = [
                ...eventsOnDay(timelineEvents.value, selectedUserId.value, day.iso).map(ev => eventItem(ev, day.iso)),
                ...segmentsForDay(day.iso).map(segment => ({
                    ...segment,
                    durationMin: segment.endMin - segment.startMin,
                })),
            ]
            return { dayIso: day.iso, items: items.sort((a, b) => a.startMin - b.startMin) }
        })
        .filter(group => group.items.length > 0)
})

function dayLabel(dayIso) {
    return nlDayName(dayIso)
}

function itemKey(item) {
    return item.kind === 'event' ? 'ev-' + item.event.id : item.kind + '-' + item.startMin
}

function gapTappable(item) {
    if (!canCreate.value) return false
    return item.kind === 'free' || props.allowOverrideUnavailability
}

function gapCaption(item) {
    const range = `${formatTimeLabel(item.startMin)} – ${formatTimeLabel(item.endMin)}`
    if (item.kind === 'blocked') {
        return gapTappable(item) ? `${range} — tik om toch in te plannen` : range
    }
    return range
}

// Naming the mechanics is what makes a shared stretch useful: "3u vrij" across
// the team means nothing without knowing whose.
function freeUsersLabel(item) {
    if (!item.userIds) return null
    if (item.userIds.length === props.plannableUsers.length) return 'Alle monteurs vrij'
    const names = item.userIds
        .map(id => props.plannableUsers.find(u => u.id === id)?.name)
        .filter(Boolean)
    if (!names.length) return null
    return names.length === 1 ? `${names[0]} vrij` : `${names.join(', ')} vrij`
}

// Lanes give each mechanic a column of their own, which the shared list cannot.
const laneView = ref(false)
const showLaneView = computed(() => showsGaps.value && selectedUserId.value === null && laneView.value)

// Breaktime comes off the mechanic's own clock, so an event's shown duration is
// not simply end - start.
function eventDurationMinutes(ev) {
    const { startMin, endMin } = eventMinutesFor(ev, selectedUserId.value, formatLocalDateAsISO(ev.start))
    const breaktime = ev.executing_users?.find(u => u.id === selectedUserId.value)?.breaktime ?? 0
    return Math.round(Math.max(0, endMin - startMin - breaktime))
}

// ── Tap-to-edit / create ─────────────────────────────────────────────────────

const authUserId = computed(() => page.props.auth.user?.id ?? null)

const relevantUserId = computed(() => selectedUserId.value ?? authUserId.value)

const unclosedCount = computed(() =>
    filteredEvents.value.filter(ev => {
        const status = completionStatusFor(ev, relevantUserId.value)
        return status === 'Gepland' || status === 'Gaande'
    }).length
)

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
        location: ev.location || '',
        executing_user_ids: [...ev.executing_user_ids],
        executing_users: [...(ev.executing_users || [])],
        no_service_order: ev.no_service_order || false,
    }
    editingExistingEvent.value = true
    modalOpen.value = true
}

function openCreateAt(start, end, executingUserIds) {
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
        executing_user_ids: executingUserIds,
    }
    editingExistingEvent.value = false
    modalOpen.value = true
}

function openCreate() {
    const start = dayjs().add(1, 'hour').startOf('hour').toDate()
    const end = dayjs(start).add(1, 'hour').toDate()
    openCreateAt(start, end, authUserId.value ? [authUserId.value] : [])
}

const {
    dialog: unavailOverrideDialog,
    request: requestOverride,
    confirm: onOverrideConfirm,
    cancel: onOverrideCancel,
} = useUnavailabilityOverride()

// Shared by the stacked gaps and the lane grid: free time opens the create
// modal straight away, blocked time only after the planner confirms the warning.
function handleSlotTap({ dayIso, userId, type, startMin, endMin }) {
    if (!canCreate.value) return
    const dayStart = dayjs(dayIso).startOf('day')
    const start = dayStart.add(startMin, 'minute').toDate()
    const end = dayStart.add(endMin, 'minute').toDate()
    const executingUserIds = userId !== null ? [userId] : []

    if (type === 'blocked') {
        if (!props.allowOverrideUnavailability) return
        const blocked = blockedUsersAt(props.plannableUsers, userId, dayIso, startMin, endMin)
        requestOverride(blocked, () => openCreateAt(start, end, executingUserIds))
        return
    }
    openCreateAt(start, end, executingUserIds)
}

const feedback = useEventFeedback()
const feedbackTitle = computed(() => feedback.activeEvent.value
    ? ('Terugkoppeling — ' + (feedback.activeEvent.value.name || ('#' + feedback.activeEvent.value.id)))
    : 'Terugkoppeling')
watch(feedback.changed, () => fetchEvents())

function closeModal() {
    modalOpen.value = false
    modalInitial.value = null
}

function onSaved() {
    closeModal()
    fetchEvents()
}
</script>
