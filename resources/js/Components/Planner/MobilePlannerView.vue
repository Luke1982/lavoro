<template>
    <div ref="rootEl" class="flex flex-col h-full bg-white dark:bg-slate-900 text-gray-900 dark:text-slate-100">
        <!--
            Alles op één regel: de week, en voor wie de hele planning ziet ook de
            monteur en de weergave. De tellers en de kaart liggen onder de drie
            puntjes; de rondleiding staat in de blauwe balk.
        -->
        <div class="sticky top-0 z-20 bg-white dark:bg-slate-900 border-b border-gray-200 dark:border-slate-800">
            <div class="flex items-center gap-1.5 px-3 py-2">
                <div class="flex items-center justify-between" :class="canSeeAll ? 'w-1/2 shrink-0' : 'min-w-0 flex-1'"
                    data-tour="planner.phone.week">
                    <button class="p-1.5 rounded-md hover:bg-gray-100 dark:hover:bg-slate-800" aria-label="Vorige week"
                        data-tour="planner.phone.prev-next" @click="shiftWeek(-1)">
                        <ChevronLeftIcon class="size-5" />
                    </button>
                    <span class="min-w-0 truncate font-semibold text-sm">{{ canSeeAll ? weekTitleShort : weekTitle }}</span>
                    <button v-if="canShiftForward" class="p-1.5 rounded-md hover:bg-gray-100 dark:hover:bg-slate-800"
                        aria-label="Volgende week" data-tour="planner.phone.prev-next" @click="shiftWeek(1)">
                        <ChevronRightIcon class="size-5" />
                    </button>
                    <span v-else class="w-8 shrink-0" />
                </div>
                <template v-if="canSeeAll">
                    <SelectMenuComponent v-model="selectedUserId" :options="userOptions" compact
                        class="min-w-0 flex-1" data-tour="planner.phone.technician" @update:model-value="rememberUser">
                        <template #sr-label>Monteur selecteren</template>
                    </SelectMenuComponent>
                    <button v-if="selectedUserId === null" data-tour="planner.phone.lanes" :class="barButton(laneView)"
                        :aria-pressed="laneView" :aria-label="laneView ? 'Lijstweergave' : 'Baanweergave'"
                        @click="laneView = !laneView">
                        <List v-if="laneView" class="size-5" />
                        <Columns3 v-else class="size-5" />
                    </button>
                </template>
                <button ref="moreButtonEl" data-tour="planner.phone.more" :class="barButton(moreOpen)"
                    :aria-expanded="moreOpen" aria-label="Meer" @click="moreOpen = !moreOpen">
                    <EllipsisVerticalIcon class="size-5" />
                </button>
            </div>
        </div>

        <Teleport to="body">
            <Transition enter-active-class="transition ease-out duration-100"
                enter-from-class="transform opacity-0 scale-95" enter-to-class="transform opacity-100 scale-100"
                leave-active-class="transition ease-in duration-75" leave-from-class="transform opacity-100 scale-100"
                leave-to-class="transform opacity-0 scale-95">
                <div v-if="moreOpen" ref="morePanelEl" data-tour="planner.phone.more.panel" :style="morePanelStyle"
                    tabindex="-1"
                    class="w-64 rounded-md bg-white dark:bg-slate-800 shadow-lg ring-1 ring-black/5 dark:ring-white/10 p-3 flex flex-col gap-3 text-sm text-gray-900 dark:text-slate-100 focus:outline-none">
                    <div class="flex flex-col gap-3" data-tour="planner.phone.stats">
                        <div v-for="stat in stats" :key="stat.label" class="flex items-center gap-2">
                            <div class="size-8 rounded-full flex items-center justify-center" :class="stat.tone">
                                <component :is="stat.icon" class="size-4" />
                            </div>
                            <div>
                                <div class="font-semibold tabular-nums text-sm">{{ stat.value }}</div>
                                <div class="text-xs text-gray-500 dark:text-slate-400">{{ stat.label }}</div>
                            </div>
                        </div>
                    </div>
                    <button v-if="canSeeAll && allPingsArray.length > 0" data-tour="planner.phone.map"
                        class="flex items-center gap-1.5 rounded-md border border-gray-200 dark:border-slate-700 px-3 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-slate-700"
                        @click="moreOpen = false; mapModalOpen = true">
                        <MapIcon class="size-4 shrink-0" />
                        Monteurkaart
                    </button>
                </div>
            </Transition>
        </Teleport>

        <!-- Op een telefoon staat de rondleiding in de blauwe balk, naast zoeken en de meldingen. -->
        <Teleport defer to="#app-header-actions">
            <TourButton tour="planner" bar class="md:hidden" data-tour="planner.phone.tour" />
        </Teleport>

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

        <!-- Timeline -->
        <PlannerLoadingOverlay :loading="eventsLoading" data-tour="planner.phone.timeline">
            <MobileLaneView v-if="showLaneView" :plannable-users="plannableUsers" :week-days="weekDays"
                :events="events" :day-start-hour="WORKDAY_START_HOUR" :day-end-hour="WORKDAY_END_HOUR"
                :allow-override-unavailability="allowOverrideUnavailability" :can-create="canCreate"
                :can-go-forward="canShiftForward" @slot-tap="handleSlotTap" @event-tap="handleEventTap"
                @flip-week="shiftWeek" />

            <div v-else class="relative h-full">
                <div ref="listScrollEl" class="h-full overflow-y-auto overscroll-y-contain" @touchstart.passive="onListTouchStart"
                    @touchmove.passive="onListTouchMove" @touchend.passive="onListTouchEnd"
                    @touchcancel.passive="onListTouchEnd">
                    <div class="pb-24">
                    <template v-for="group in groupedByDay" :key="group.dayIso">
                        <!-- Sticky day header -->
                        <div data-tour="planner.phone.day"
                            class="sticky top-0 z-20 flex items-center justify-between gap-2 px-4 py-1.5 backdrop-blur-sm border-b border-gray-200 dark:border-slate-700 text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide"
                            :class="group.holiday ? HOLIDAY_BAND : 'bg-gray-50/95 dark:bg-slate-800/95'">
                            <span>
                                {{ dayLabel(group.dayIso) }}
                                <span v-if="showsGaps" class="font-normal normal-case">{{ dayjs(group.dayIso).format('D MMM')
                                    }}</span>
                                <span v-if="group.holiday" class="ml-1 normal-case" :class="HOLIDAY_LABEL">{{ group.holiday }}</span>
                            </span>
                            <DayRouteLinks v-if="shownRoute(group.dayIso)" :route="shownRoute(group.dayIso)"
                                @remove="removeRoute" />
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
                                    <MobileEventCard :event="item.event" :selected-user-id="selectedUserId" data-tour="planner.phone.event"
                                        :is-highlighted="highlightedEventId === item.event.id"
                                        :relevant-user-id="relevantUserId" :can-see-all="canSeeAll"
                                        :can-edit="canEdit(item.event)" :plannable-users="plannableUsers"
                                        :user-roles="userRoles" @tap="handleEventTap"
                                        @feedback="feedback.openFeedback" @changed="fetchEvents" />
                                </div>
                            </div>
                        </div>
                        </template>

                        <div v-if="!eventsLoading && !showsGaps && timelineEvents.length === 0"
                            class="flex items-center justify-center h-40 text-sm text-gray-500 dark:text-slate-400">
                            Geen afspraken deze week
                        </div>
                    </div>
                </div>
                <WeekFlipIndicator :pull="listPull" :armed="listPullArmed" :progress="listPullProgress" />
            </div>
        </PlannerLoadingOverlay>

        <!-- FAB: create new event -->
        <button v-if="canCreate" data-tour="planner.phone.add"
            :style="{ bottom: `${corner.bottom + 16}px`, right: `${corner.right + 24}px` }"
            class="fixed z-30 size-14 rounded-full bg-lavoro-green shadow-lg flex items-center justify-center hover:opacity-90 active:scale-95 transition-[bottom,right,opacity,scale] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]"
            aria-label="Nieuwe afspraak aanmaken" @click="openCreate">
            <PlusIcon class="size-7 text-gray-900 dark:text-slate-100" />
        </button>

        <!-- Edit modal -->
        <EventEditModal v-if="modalOpen" :event-types="eventTypes" :event-statusses="eventStatusses"
            :all-customers="allCustomers" :customers-use-ajax="customersUseAjax" :all-service-orders="allServiceOrders"
            :all-users="allUsers" :user-roles="userRoles" :initial="modalInitial"
            :editing-existing="editingExistingEvent" allow-delete @close="closeModal" @saved="closeAndRefresh"
            @deleted="closeAndRefresh" />

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
                    @images-uploaded="feedback.onImagesUploaded" @image-updated="feedback.onImageUpdated"
                    @image-deleted="feedback.onImageDeleted" />
            </div>
        </ModalDialog>
    </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    EllipsisVerticalIcon,
    CalendarDaysIcon,
    ClockIcon,
    PlusIcon,
} from '@heroicons/vue/24/outline'
import dayjs from '@/Utilities/dayjs'
import { hasPermission, initials, nlDayName, formatLocalDateAsISO } from '@/Utilities/Utilities'
import { HOLIDAY_BAND, HOLIDAY_LABEL, plannerDays, publicHolidayOn } from '@/Utilities/publicHolidays'
import {
    UNAVAILABLE_PATTERN,
    completionStatusFor,
    formatDurationLabel,
    formatTimeLabel,
} from '@/Utilities/plannerOverlaps'
import { usePlannerEvents } from '@/Composables/usePlannerEvents'
import { usePlannerDayRoutes } from '@/Composables/usePlannerDayRoutes'
import DayRouteLinks from '@/Components/Planner/DayRouteLinks.vue'
import {
    daySegmentsAcrossUsers,
    daySegmentsFor,
    eventMinutesFor,
    eventsOnDay,
} from '@/Composables/usePlannerGaps'
import { blockedUsersAt, useUnavailabilityOverride } from '@/Composables/useUnavailability'
import { usePullToFlip } from '@/Composables/usePullToFlip'
import { browserStorage } from '@/Utilities/browserStorage'
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
import { takeJumpTarget, waitForEventElement } from '@/Composables/usePlannerJump'
import { MapIcon, TriangleAlert, Columns3, List } from '@lucide/vue'
import TourButton from '@/Components/Tour/TourButton.vue'
import { useTour } from '@/Composables/useTour'
import { useBottomBars } from '@/Composables/useBottomBars.js'
import { usePopover } from '@/Composables/usePopover'
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

const MONTHS = ['januari', 'februari', 'maart', 'april', 'mei', 'juni',
    'juli', 'augustus', 'september', 'oktober', 'november', 'december']
const SHORT_MONTHS = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec']

const page = usePage()
const mapModalOpen = ref(false)
const allPingsArray = computed(() =>
    Object.values(props.latestPings).filter(p => p.lat != null && p.lng != null)
)

// ── Top row ───────────────────────────────────────────────────────────────────

/** Een knop op de regel bovenin, ingedrukt zolang wat hij aanzet aan staat. */
function barButton(pressed) {
    return [
        'flex size-9 shrink-0 items-center justify-center rounded-md ring-1 ring-gray-200 dark:ring-slate-700 hover:bg-gray-50 dark:hover:bg-slate-800',
        pressed ? 'bg-gray-100 dark:bg-slate-800' : '',
    ]
}

const moreButtonEl = ref(null)
const morePanelEl = ref(null)
const { open: moreOpen, style: morePanelStyle } = usePopover(moreButtonEl, morePanelEl, 'bottom-end')

// ── Week navigation ───────────────────────────────────────────────────────────

function startOfWeek(date) {
    return dayjs(date).startOf('isoWeek').toDate()
}

const weekStart = ref(startOfWeek(new Date()))

const weekDays = computed(() => plannerDays(weekStart.value, 7))

const weekTitle = computed(() => {
    const first = weekDays.value[0].date
    const last = weekDays.value[6].date
    const sameMonth = first.getMonth() === last.getMonth()
    const sameYear = first.getFullYear() === last.getFullYear()
    if (sameMonth && sameYear) {
        return `${first.getDate()} – ${last.getDate()} ${MONTHS[first.getMonth()]} ${first.getFullYear()}`
    }
    if (sameYear) {
        return `${first.getDate()} ${MONTHS[first.getMonth()]} – ${last.getDate()} ${MONTHS[last.getMonth()]} ${first.getFullYear()}`
    }
    return `${first.getDate()} ${MONTHS[first.getMonth()]} ${first.getFullYear()} – ${last.getDate()} ${MONTHS[last.getMonth()]} ${last.getFullYear()}`
})

/** Past naast de monteur op één regel: zonder jaar en met de maand kort. */
const weekTitleShort = computed(() => {
    const first = weekDays.value[0].date
    const last = weekDays.value[6].date
    const lastLabel = last.getDate() + ' ' + SHORT_MONTHS[last.getMonth()]

    if (first.getMonth() === last.getMonth()) {
        return first.getDate() + ' – ' + lastLabel
    }

    return first.getDate() + ' ' + SHORT_MONTHS[first.getMonth()] + ' – ' + lastLabel
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

onMounted(() => {
    fetchEvents()
    startPolling()
    jumpToTarget()
})

onUnmounted(stopPolling)
watch(weekStart, () => { resetFingerprint(); fetchEvents() })

const { routeFor, removeRoute } = usePlannerDayRoutes(weekStart, dayCount)
const { corner } = useBottomBars()

// ── User switcher ─────────────────────────────────────────────────────────────

const canSeeAll = computed(() => hasPermission('event.see_all'))

const authUserId = computed(() => page.props.auth.user?.id ?? null)

/**
 * Wie de hele planning ziet, krijgt op dit toestel de monteur terug die het
 * laatst in het menu gekozen is. Alleen het menu telt: dat een sprong naar een
 * afspraak het filter openzet, is geen keuze. Een onthouden monteur die niet
 * meer inplanbaar is, valt terug op alle monteurs.
 */
const rememberedUserKey = 'lavoro_mobile_planner_user_' + authUserId.value

function rememberedUserId() {
    const userId = Number(browserStorage.get(rememberedUserKey))

    return props.plannableUsers.some(u => u.id === userId) ? userId : null
}

function rememberUser(userId) {
    if (userId === null) {
        browserStorage.forget(rememberedUserKey)
    } else {
        browserStorage.set(rememberedUserKey, String(userId))
    }
}

const selectedUserId = ref(canSeeAll.value ? rememberedUserId() : authUserId.value)

const userOptions = computed(() => [
    { value: null, title: 'Alle monteurs', shortTitle: 'Allen' },
    ...props.plannableUsers.map(u => ({ value: u.id, title: u.name, shortTitle: u.name.split(' ')[0] })),
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

// ── Springen naar één afspraak ────────────────────────────────────────────────

const highlightedEventId = ref(null)
let highlightTimer = null

/**
 * Dezelfde sprong als op een breed scherm, met de middelen die deze weergave
 * heeft: geen rijen om open te klappen, wel een filter op monteur dat de afspraak
 * kan wegfilteren. Staat de aangewezen monteur er niet bij, dan gaat het filter
 * open voor wie de hele planning mag zien, en anders blijft het staan: iemand een
 * afspraak laten zien die niet van hem is, is geen hulp.
 */
async function jumpToTarget() {
    const target = takeJumpTarget()

    if (!target) return

    weekStart.value = startOfWeek(new Date(target.start))

    const crew = target.executing_users.map(u => u.id)

    if (canSeeAll.value && selectedUserId.value !== null && crew.length && !crew.includes(selectedUserId.value)) {
        selectedUserId.value = null
    }

    await nextTick()

    const el = await waitForEventElement(listScrollEl, target.id)

    if (!el) return

    highlightedEventId.value = target.id
    el.scrollIntoView({ behavior: 'smooth', block: 'center' })
    clearTimeout(highlightTimer)
    highlightTimer = setTimeout(() => { highlightedEventId.value = null }, 3000)
}

onUnmounted(() => clearTimeout(highlightTimer))

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

/** A day only shows a route for one person: the one whose planning is on screen. */
function shownRoute(dayIso) {
    return selectedUserId.value === null ? null : routeFor(selectedUserId.value, dayIso)
}

function dayGroup(dayIso, items = []) {
    return { dayIso, holiday: publicHolidayOn(dayIso), items }
}

/** A day is listed when it holds something: an item, a route or a public holiday. */
function holdsSomething(group) {
    return group.items.length > 0 || !!shownRoute(group.dayIso) || !!group.holiday
}

const groupedByDay = computed(() => {
    if (!showsGaps.value) {
        const groups = []
        for (const ev of timelineEvents.value) {
            const dayIso = formatLocalDateAsISO(ev.start)
            if (groups.at(-1)?.dayIso !== dayIso) groups.push(dayGroup(dayIso))
            groups.at(-1).items.push(eventItem(ev, dayIso))
        }
        const eventless = weekDays.value
            .filter(day => !groups.some(group => group.dayIso === day.iso))
            .map(day => dayGroup(day.iso))
        return [...groups, ...eventless.filter(holdsSomething)].sort((a, b) => a.dayIso.localeCompare(b.dayIso))
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
            return dayGroup(day.iso, items.sort((a, b) => a.startMin - b.startMin))
        })
        .filter(holdsSomething)
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

const relevantUserId = computed(() => selectedUserId.value ?? authUserId.value)

const unclosedCount = computed(() =>
    filteredEvents.value.filter(ev => {
        const status = completionStatusFor(ev, relevantUserId.value)
        return status === 'Gepland' || status === 'Gaande'
    }).length
)

const STAT_BLUE = 'bg-blue-50 dark:bg-blue-950/40 text-blue-500'

/** De tellers onder de drie puntjes; wat niet afgerond is alleen als het er is. */
const stats = computed(() => {
    const shown = [
        { label: 'Afspraken', value: filteredEvents.value.length, icon: CalendarDaysIcon, tone: STAT_BLUE },
        { label: 'Gepland', value: totalHoursLabel.value, icon: ClockIcon, tone: STAT_BLUE },
    ]

    if (unclosedCount.value > 0) {
        shown.push({ label: 'Niet afgerond', value: unclosedCount.value, icon: TriangleAlert, tone: 'bg-amber-50 dark:bg-amber-950/40 text-amber-500' })
    }

    return shown
})

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
        location: ev.own_location || '',
        location_id: ev.location_id ?? null,
        inherited_location: ev.inherited_location || '',
        has_deviating_location: ev.has_deviating_location || false,
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

// A save and a delete both leave the shown week stale: close and refetch either way.
function closeAndRefresh() {
    closeModal()
    fetchEvents()
}

/** Wat de rondleiding op de telefoon laat zien; alles gaat terug zoals het stond. */
useTour('planner', {
    showLaneView: () => {
        if (!canSeeAll.value || showLaneView.value) return null
        const previous = { user: selectedUserId.value, lanes: laneView.value }
        selectedUserId.value = null
        laneView.value = true

        return () => {
            laneView.value = previous.lanes
            selectedUserId.value = previous.user
        }
    },
    openPhoneMore: () => {
        if (moreOpen.value) return null
        moreOpen.value = true

        return () => { moreOpen.value = false }
    },
    openFirstPhoneEvent: () => {
        const event = timelineEvents.value.find(canEdit)
        if (modalOpen.value || !event) return null
        handleEventTap(event)

        return () => closeModal()
    },
})
</script>

