<template>
    <div class="flex flex-col h-full  dark:bg-slate-900 text-gray-900 dark:text-slate-100"
        @pointermove="onWindowPointerMove" @pointerup="onWindowPointerUp">
        <!-- Top toolbar -->
        <div
            class="relative z-30 flex items-center px-4 py-3 border-b border-gray-200 dark:border-slate-800 gap-3 flex-wrap  dark:bg-slate-900">
            <h1 class="text-xl font-bold pr-4">Planning</h1>
            <button
                class="rounded-md border border-gray-300 dark:border-slate-700 px-3 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-slate-800"
                @click="goToday">Vandaag</button>
            <button
                class="rounded-md border border-gray-300 dark:border-slate-700 p-1.5 hover:bg-gray-50 dark:hover:bg-slate-800"
                @click="shiftWeek(-1)" aria-label="Vorige week">
                <ChevronLeftIcon class="size-4" />
            </button>
            <button
                class="rounded-md border border-gray-300 dark:border-slate-700 p-1.5 hover:bg-gray-50 dark:hover:bg-slate-800"
                @click="shiftWeek(1)" aria-label="Volgende week">
                <ChevronRightIcon class="size-4" />
            </button>
            <div class="font-semibold text-sm">{{ weekTitle }}</div>

            <div class="ml-auto flex items-center gap-2">
                <SelectMenuComponent v-model="slotMinutes" :options="slotOptions" :icon="Squares2X2Icon">
                    <template #sr-label>Slotgrootte</template>
                </SelectMenuComponent>
                <label class="text-xs text-gray-500 dark:text-slate-400 ml-2">Dag</label>
                <select v-model.number="dayStartHour"
                    class="rounded-md border border-gray-300 dark:border-slate-700  dark:bg-slate-800 text-sm px-2 py-1">
                    <option v-for="h in 12" :key="h - 1" :value="h - 1">{{ String(h - 1).padStart(2, '0') }}:00</option>
                </select>
                <span class="text-xs">tot</span>
                <select v-model.number="dayEndHour"
                    class="rounded-md border border-gray-300 dark:border-slate-700  dark:bg-slate-800 text-sm px-2 py-1">
                    <option v-for="h in 24" :key="h" :value="h">{{ String(h).padStart(2, '0') }}:00</option>
                </select>
            </div>
        </div>

        <div class="flex flex-1 min-h-0">
            <!-- Resource sidebar -->
            <div class="w-64 shrink-0 border-r border-gray-200 dark:border-slate-800 flex flex-col">
                <div class="border-b border-gray-200 dark:border-slate-800 px-4 flex items-end justify-between gap-2 pb-2 text-xs text-gray-500 dark:text-slate-400"
                    :style="{ height: headerHeight + 'px' }">
                    <span>Monteurs ({{ visibleUsers.length }})</span>
                    <button v-if="visibleUsers.length" @click="toggleAllRows"
                        class="flex items-center gap-0.5 rounded px-1.5 py-1 hover:bg-gray-100 dark:hover:bg-slate-800 font-medium">
                        <ChevronDownIcon v-if="allRowsCollapsed" class="size-3.5" />
                        <ChevronRightIcon v-else class="size-3.5" />
                        {{ allRowsCollapsed ? 'Alles uitklappen' : 'Alles inklappen' }}
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto" ref="sidebarScrollRef">
                    <div v-if="showProjects && allDayLaneHeight" :style="{ height: allDayLaneHeight + 'px' }"
                        class="relative border-b border-gray-200 dark:border-slate-800 text-xs font-medium text-gray-500 dark:text-slate-400 bg-gray-50/40 dark:bg-slate-800/40 transition-[height] duration-200 ease-in-out">
                        <button
                            class="absolute top-1.5 left-1.5 rounded p-0.5 hover:bg-gray-200 dark:hover:bg-slate-700"
                            @click="toggleAllDay"
                            :aria-label="allDayCollapsed ? 'Projecten uitklappen' : 'Projecten inklappen'">
                            <ChevronDownIcon v-if="!allDayCollapsed" class="size-4" />
                            <ChevronRightIcon v-else class="size-4" />
                        </button>
                        <span class="block pl-9 pt-2">Projecten ({{ allDay.tracks.length }})</span>
                    </div>
                    <div v-for="(user, idx) in visibleUsers" :key="user.id"
                        :style="{ height: rowHeightFor(user.id) + 'px' }"
                        class="relative flex items-center gap-2 pl-9 pr-2 border-b border-gray-100 dark:border-slate-800 transition-[height] duration-200 ease-in-out"
                        :class="idx % 2 === 1 ? 'bg-gray-50/40 dark:bg-slate-800/40' : ''">
                        <button
                            class="absolute top-1.5 left-1.5 rounded p-0.5 hover:bg-gray-200 dark:hover:bg-slate-700 text-gray-400"
                            @click="toggleUserRow(user.id)"
                            :aria-label="collapsedUsers.has(user.id) ? 'Rij uitklappen' : 'Rij inklappen'">
                            <ChevronDownIcon v-if="!collapsedUsers.has(user.id)" class="size-4" />
                            <ChevronRightIcon v-else class="size-4" />
                        </button>
                        <div class="rounded-full bg-gray-200 dark:bg-slate-700 flex items-center justify-center overflow-hidden text-xs font-semibold ring-1 ring-gray-300 dark:ring-slate-700 shrink-0 transition-all duration-200 ease-in-out"
                            :class="collapsedUsers.has(user.id) ? 'h-7 w-7' : 'h-10 w-10'">
                            <img v-if="user.avatar" :src="user.avatar" class="object-cover w-full h-full"
                                :alt="user.name" />
                            <span v-else>{{ initials(user.name) }}</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold truncate">{{ user.name }}</div>
                            <div v-if="!collapsedUsers.has(user.id)" class="text-xs text-gray-500 dark:text-slate-400">
                                {{
                                    userHoursLabel(user.id) }}</div>
                        </div>
                    </div>
                    <div v-if="visibleUsers.length === 0" class="p-4 text-xs text-gray-500 dark:text-slate-400">
                        Geen inplanbare monteurs.
                        Schakel "Inplanbaar" in op een gebruiker via Gebruikers.
                    </div>
                </div>
            </div>

            <!-- Time grid -->
            <div class="flex-1 overflow-auto relative" ref="gridScrollRef" @scroll="onGridScroll"
                @dragleave="onGridDragLeave">
                <!-- Headers (sticky) -->
                <div class="sticky top-0 z-20  dark:bg-slate-900">
                    <div class="grid border-b border-gray-200 dark:border-slate-800"
                        :style="{ gridTemplateColumns: dayGridTemplate, minWidth: gridMinWidth + 'px', height: dayHeaderHeight + 'px' }">
                        <div v-for="day in weekDays" :key="'dh-' + day.iso"
                            class="px-3 flex items-center justify-center text-sm font-semibold border-l border-gray-200 dark:border-slate-800 first:border-l-0">
                            <span class="uppercase">{{ dayLabel(day.date) }}</span>
                            <span v-if="isToday(day.date)"
                                class="inline-block ml-2 rounded-full bg-blue-600 text-white text-xs px-2 py-0.5">
                                {{ String(day.date.getDate()).padStart(2, '0') }}
                            </span>
                        </div>
                    </div>
                    <div class="grid border-b border-gray-200 dark:border-slate-800  dark:bg-slate-900 text-[11px] text-gray-500 dark:text-slate-400"
                        :style="{ gridTemplateColumns: dayGridTemplate, minWidth: gridMinWidth + 'px', height: hourHeaderHeight + 'px' }">
                        <div v-for="day in weekDays" :key="'hh-' + day.iso"
                            class="grid border-l border-gray-200 dark:border-slate-800 first:border-l-0 relative"
                            :style="{ gridTemplateColumns: `repeat(${hourCount}, minmax(0, 1fr))` }">
                            <div v-for="h in hourCount" :key="'hl-' + day.iso + '-' + h"
                                class="border-l border-gray-100 dark:border-slate-800/70 first:border-l-0 flex items-end pb-1 pl-1">
                                {{ String(dayStartHour + h - 1).padStart(2, '0') }}:00
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Body rows -->
                <div class="relative" :style="{ minWidth: gridMinWidth + 'px' }" ref="bodyRef">
                    <!-- All-day project band -->
                    <div v-if="showProjects && allDayLaneHeight"
                        class="relative border-b border-gray-200 dark:border-slate-800 bg-gray-50/30 dark:bg-slate-900/30 transition-[height] duration-200 ease-in-out"
                        :style="{ height: allDayLaneHeight + 'px', minWidth: gridMinWidth + 'px' }">
                        <!-- Day gridlines -->
                        <div class="absolute inset-0 grid pointer-events-none"
                            :style="{ gridTemplateColumns: dayGridTemplate }">
                            <div v-for="day in weekDays" :key="'adg-' + day.iso"
                                class="border-l border-gray-100 dark:border-slate-800/60 first:border-l-0" />
                        </div>

                        <template v-for="track in visibleTracks" :key="'track-' + track.id">
                            <!-- Project bar (background spans full range; label sticks to the viewport) -->
                            <div class="absolute rounded-md border bg-indigo-50 dark:bg-indigo-950/50 border-indigo-300 dark:border-indigo-800"
                                :style="{ left: track.left + 'px', width: track.width + 'px', top: track.top + 'px', height: PROJECT_BAR_H + 'px' }"
                                :title="`${track.title}${track.customerName ? ' — ' + track.customerName : ''}`">
                                <div class="sticky left-0 inline-block max-w-full px-2 py-1">
                                    <div
                                        class="text-xs font-semibold leading-tight truncate text-indigo-900 dark:text-indigo-200">
                                        {{ track.continuesLeft ? '◂ ' : '' }}{{ track.title }}{{ track.continuesRight ?
                                            ' ▸' : '' }}
                                    </div>
                                    <div v-if="track.customerName"
                                        class="text-[10px] leading-tight truncate text-indigo-600/80 dark:text-indigo-300/80">
                                        {{ track.customerName }}
                                    </div>
                                </div>
                            </div>

                            <!-- Unplanned service orders hanging below the project (side by side, wrapping) -->
                            <div v-if="track.serviceOrders.length" class="absolute"
                                :style="{ left: track.left + 'px', width: track.width + 'px', top: track.hangingTop + 'px' }">
                                <!-- inline-flex shrinks to content so it has slack to stick left; capped at the project width so wrapping matches the reserved height -->
                                <div class="sticky left-0 inline-flex flex-wrap content-start gap-1"
                                    :style="{ maxWidth: track.width + 'px' }">
                                    <div v-for="so in track.serviceOrders" :key="'pso-' + so.id" draggable="true"
                                        @dragstart="onProjectServiceOrderDragStart($event, so)"
                                        @dragend="onProjectServiceOrderDragEnd"
                                        :style="{ height: SO_CARD_H + 'px', width: SO_CARD_W + 'px' }"
                                        class="group cursor-grab active:cursor-grabbing select-none flex items-center gap-1.5 rounded-md border border-gray-200 dark:border-slate-700  dark:bg-slate-800 px-2 shadow-sm hover:border-lavoro-blue transition"
                                        :title="`Sleep naar de planning — werkbon #${so.id}`">
                                        <ArrowsRightLeftIcon
                                            class="size-3 shrink-0 text-gray-400 dark:text-slate-500" />
                                        <span class="text-xs font-semibold shrink-0">#{{ so.id }}</span>
                                        <span class="text-[11px] text-gray-500 dark:text-slate-400 truncate">{{
                                            so.description || 'Werkbon' }}</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Locked-event group overlays -->
                    <div v-for="ov in lockedGroupOverlays" :key="'lock-ov-' + ov.id"
                        class="absolute pointer-events-none z-[6] rounded-xl border-2 border-dashed" :style="{
                            top: ov.top - 6 + 'px',
                            height: ov.height + 12 + 'px',
                            left: `calc(${ov.leftPct}%  - 5px)`,
                            width: `calc(${ov.widthPct}% + 11px)`,
                            borderColor: ov.color,
                            opacity: 0.5,
                        }" />

                    <div v-for="(user, idx) in visibleUsers" :key="'row-' + user.id"
                        class="grid relative transition-[height] duration-200 ease-in-out"
                        :style="{ gridTemplateColumns: dayGridTemplate, height: rowHeightFor(user.id) + 'px' }"
                        :class="idx % 2 === 1 ? 'bg-gray-50/40 dark:bg-slate-800/40' : ''">

                        <div v-for="day in weekDays" :key="'cell-' + user.id + '-' + day.iso"
                            class="relative border-l border-b border-gray-200 dark:border-slate-800 first:border-l-0"
                            :data-user-id="user.id" :data-day-iso="day.iso"
                            @pointerdown="onCellPointerDown($event, user, day)"
                            @dragover.prevent="onDragOver($event, user, day)"
                            @drop.prevent="onExternalDrop($event, user, day)">
                            <!-- Hour grid lines -->
                            <div class="absolute inset-0 grid pointer-events-none"
                                :style="{ gridTemplateColumns: `repeat(${hourCount}, minmax(0, 1fr))` }">
                                <div v-for="h in hourCount" :key="'hgl-' + user.id + '-' + day.iso + '-' + h"
                                    class="border-l border-gray-100 dark:border-slate-800/60 first:border-l-0" />
                            </div>

                            <!-- Unavailability overlays -->
                            <template v-for="(overlay, oi) in getBlockOverlays(user.id, day.iso)"
                                :key="'block-' + user.id + '-' + day.iso + '-' + oi">
                                <div class="absolute top-0 bottom-0 pointer-events-none z-[5] flex items-center overflow-hidden"
                                    :style="{
                                        left: overlay.left + '%',
                                        width: overlay.width + '%',
                                        background: 'repeating-linear-gradient(-45deg, transparent, transparent 4px, rgba(156,163,175,0.35) 4px, rgba(156,163,175,0.35) 8px)',
                                    }">
                                    <span
                                        class="text-[10px] font-medium text-gray-500 dark:text-gray-400 px-1.5 truncate select-none whitespace-nowrap">
                                        {{ overlay.label || 'Niet beschikbaar' }}
                                    </span>
                                </div>
                            </template>

                            <!-- Now indicator -->
                            <div v-if="isToday(day.date) && nowOffsetPercent !== null"
                                class="absolute top-0 bottom-0 w-px bg-red-500/70 pointer-events-none z-10"
                                :style="{ left: nowOffsetPercent + '%' }">
                                <div class="absolute -top-1 -translate-x-1/2 size-2 rounded-full bg-red-500"></div>
                            </div>

                            <!-- Events for this user/day -->
                            <PlannerEvent v-for="ev in eventsFor(user.id, day.iso)" :key="ev.id + '-' + user.id"
                                :event="ev" :user-id="user.id" :day="day" :slot-minutes="slotMinutes"
                                :day-start-hour="dayStartHour" :day-end-hour="dayEndHour"
                                :row-height="rowHeightFor(user.id)" :event-padding-y="paddingYFor(user.id)"
                                :is-locked="ev.executing_user_ids.length > 1" :is-being-dragged="drag.eventId === ev.id"
                                @click="handleEventClick(ev)" @contextmenu="onEventContextMenu($event, ev)"
                                @pointerdown-on-event="onEventPointerDown($event, ev, user)"
                                @pointerdown-on-resize="onResizePointerDown($event, ev, user, $event.edge)" />

                            <!-- Live selection rectangle (click-drag-create) -->
                            <div v-if="selectRect && selectRect.userId === user.id && selectRect.dayIso === day.iso"
                                class="absolute top-1 bottom-1 bg-blue-500/30 border-2 border-dashed border-blue-500 rounded-md pointer-events-none"
                                :style="{ left: selectRect.left + '%', width: selectRect.width + '%' }">
                                <div
                                    class="absolute -top-5 left-1 text-[10px] font-semibold text-blue-700 dark:text-blue-300  dark:bg-slate-900 rounded px-1">
                                    {{ formatTimeFromMinutes(selectRect.startMinutes) }} –
                                    {{ formatTimeFromMinutes(selectRect.endMinutes) }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Drag ghost (floats above grid) -->
                    <div v-if="dragGhost"
                        class="absolute pointer-events-none rounded-md border-2 border-dashed /90 dark:bg-slate-800/90 shadow-lg z-30 px-2 py-1 text-xs"
                        :style="dragGhost.style">
                        <div class="font-semibold truncate">{{ dragGhost.title }}</div>
                        <div class="text-[11px]">
                            {{ formatTimeFromDate(dragGhost.start) }} – {{ formatTimeFromDate(dragGhost.end) }}
                        </div>
                        <div v-if="dragGhost.userName" class="text-[10px] opacity-75">→ {{ dragGhost.userName }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/edit modal -->
        <EventEditModal v-if="modalOpen" :event-types="eventTypes" :event-statusses="eventStatusses"
            :all-customers="allCustomers" :customers-use-ajax="customersUseAjax" :all-service-orders="allServiceOrders"
            :all-users="allUsers" :initial="modalInitial" :editing-existing="editingExistingEvent" @close="closeModal"
            @saved="onSaved" />
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import axios from 'axios'
import { ChevronLeftIcon, ChevronRightIcon, ChevronDownIcon, Squares2X2Icon, ArrowsRightLeftIcon } from '@heroicons/vue/24/outline'
import { initials, formatLocalDateAsISO, formatUtcDatetime, nlTime, hasPermission } from '@/Utilities/Utilities'
import { setServiceOrderDragData } from '@/Utilities/plannerDnd'
import dayjs from '@/Utilities/dayjs'
import PlannerEvent from '@/Components/Planner/PlannerEvent.vue'
import EventEditModal from '@/Components/Planner/EventEditModal.vue'
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue'
import ContextMenu from '@imengyu/vue3-context-menu'

const props = defineProps({
    eventTypes: { type: Array, default: () => [] },
    allCustomers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    allServiceOrders: { type: Array, default: () => [] },
    eventStatusses: { type: Array, default: () => [] },
    allUsers: { type: Array, default: () => [] },
    plannableUsers: { type: Array, default: () => [] },
    /** Projects rendered as all-day bars in the row above the resource lanes */
    projects: { type: Array, default: () => [] },
    /** Default slot snap in minutes */
    defaultSlotMinutes: { type: Number, default: 30 },
    /** Default visible day hours */
    defaultDayStartHour: { type: Number, default: 7 },
    defaultDayEndHour: { type: Number, default: 18 },
    /** Row height in px (so we can keep rows equal as the screenshot requires) */
    rowHeight: { type: Number, default: 120 },
    /** Vertical padding around event cards within each lane */
    eventPaddingY: { type: Number, default: 14 },
})

const emit = defineEmits(['service-order-planned', 'service-order-unplanned'])

const page = usePage()

const authUserId = computed(() => page.props.auth?.user?.id ?? null)

function canEditEvent(ev) {
    if (hasPermission('event.update_others')) return true
    return hasPermission('event.update') && ev.executing_user_ids.includes(authUserId.value)
}

function canDeleteEvent(ev) {
    if (hasPermission('event.delete_others')) return true
    return hasPermission('event.delete') && ev.executing_user_ids.includes(authUserId.value)
}

const visibleUsers = computed(() =>
    hasPermission('event.see_all')
        ? props.plannableUsers
        : props.plannableUsers.filter(u => u.id === authUserId.value)
)

const showProjects = computed(() => hasPermission('project.read'))

const slotMinutes = ref(props.defaultSlotMinutes)
const dayStartHour = ref(props.defaultDayStartHour)
const dayEndHour = ref(props.defaultDayEndHour)

const events = ref([])
const unavailabilities = ref([])
const weekStart = ref(startOfWeek(new Date()))

const sidebarScrollRef = ref(null)
const gridScrollRef = ref(null)
const bodyRef = ref(null)

const modalOpen = ref(false)
const editingExistingEvent = ref(false)
const modalInitial = ref(null)

const drag = ref({ eventId: null, mode: null })
const dragGhost = ref(null)
const selectRect = ref(null)
let suppressClickUntil = 0

const HOUR_PX_MIN = 60
const SLOT_PX_MIN = 56
const dayHeaderHeight = 44
const hourHeaderHeight = 44
const headerHeight = dayHeaderHeight + hourHeaderHeight

const slotOptions = [
    { value: 15, title: '15 min per slot', shortTitle: '15 min', description: 'Bredere kolommen, ideaal voor korte afspraken' },
    { value: 30, title: '30 min per slot', shortTitle: '30 min', description: 'Standaard slotgrootte' },
    { value: 60, title: '60 min per slot', shortTitle: '60 min', description: 'Compactere weergave, voor lange afspraken' },
]

const hourCount = computed(() => Math.max(1, dayEndHour.value - dayStartHour.value))
const slotsPerHour = computed(() => 60 / slotMinutes.value)
const hourWidthPx = computed(() => Math.max(HOUR_PX_MIN, slotsPerHour.value * SLOT_PX_MIN))
const dayWidthPx = computed(() => hourWidthPx.value * hourCount.value)
const gridMinWidth = computed(() => dayWidthPx.value * 7)
const dayGridTemplate = computed(() => `repeat(7, minmax(${dayWidthPx.value}px, 1fr))`)

// --- All-day project band (row above the resource lanes) ---
const PROJECT_BAR_H = 38
const SO_CARD_H = 34
const SO_CARD_W = 150 // hanging service-order card width
const SO_GAP = 4
const TRACK_TOP_PAD = 6
const TRACK_BOTTOM_PAD = 10
const COLLAPSED_LANE_H = 32 // all-day lane height when collapsed
const COLLAPSED_ROW_H = 40 // resource row height when collapsed

const allDayCollapsed = ref(false)
const collapsedUsers = ref(new Set())

/** One horizontal track per project, positioned within the visible week. */
const allDay = computed(() => {
    const ws = dayjs(weekStart.value).startOf('day')
    const weekEnd = ws.add(7, 'day') // exclusive: start of the day after the week
    const tracks = []
    let top = TRACK_TOP_PAD
    for (const p of props.projects) {
        if (!p.start_date || !p.end_date) continue
        const start = dayjs(p.start_date).startOf('day')
        const endExclusive = dayjs(p.end_date).startOf('day').add(1, 'day') // through end of end_date (23:59)
        if (!endExclusive.isAfter(ws) || !start.isBefore(weekEnd)) continue // not visible this week
        const visibleStart = start.isAfter(ws) ? start : ws
        const visibleEnd = endExclusive.isBefore(weekEnd) ? endExclusive : weekEnd
        const startDay = Math.round(visibleStart.diff(ws, 'day', true))
        const endDayExclusive = Math.round(visibleEnd.diff(ws, 'day', true))
        const width = (endDayExclusive - startDay) * dayWidthPx.value
        const serviceOrders = p.service_orders || []
        // Hanging cards sit side by side and wrap within the project width.
        const perRow = Math.max(1, Math.floor((width + SO_GAP) / (SO_CARD_W + SO_GAP)))
        const rows = serviceOrders.length ? Math.ceil(serviceOrders.length / perRow) : 0
        const hangingHeight = rows ? rows * (SO_CARD_H + SO_GAP) + SO_GAP : 0
        tracks.push({
            id: p.id,
            project: p,
            title: p.title || `Project #${p.id}`,
            customerName: p.customer?.name || '',
            left: startDay * dayWidthPx.value,
            width,
            top,
            hangingTop: top + PROJECT_BAR_H,
            serviceOrders,
            continuesLeft: start.isBefore(ws),
            continuesRight: endExclusive.isAfter(weekEnd),
        })
        top += PROJECT_BAR_H + hangingHeight + TRACK_BOTTOM_PAD
    }
    return { tracks, height: tracks.length ? top : 0 }
})

// Effective lane height honoring the collapse toggle.
const allDayLaneHeight = computed(() => {
    if (!allDay.value.height) return 0
    return allDayCollapsed.value ? COLLAPSED_LANE_H : allDay.value.height
})

// Tracks to actually render (none while the lane is collapsed).
const visibleTracks = computed(() => (allDayCollapsed.value ? [] : allDay.value.tracks))

const lockedGroupOverlays = computed(() => {
    const totalMin = (dayEndHour.value - dayStartHour.value) * 60
    if (totalMin <= 0) return []
    const users = visibleUsers.value
    const days = weekDays.value

    let rowTop = allDayLaneHeight.value
    const rowTops = users.map(u => {
        const t = rowTop
        rowTop += rowHeightFor(u.id)
        return t
    })

    return events.value
        .filter(ev => ev.executing_user_ids.length > 1)
        .map(ev => {
            const evDayIso = formatLocalDateAsISO(ev.start)
            const dayIdx = days.findIndex(d => d.iso === evDayIso)
            if (dayIdx === -1) return null

            const userIndices = ev.executing_user_ids
                .map(uid => users.findIndex(u => u.id === uid))
                .filter(i => i !== -1)
            if (userIndices.length < 2) return null

            const minIdx = Math.min(...userIndices)
            const maxIdx = Math.max(...userIndices)

            const topPad = paddingYFor(users[minIdx].id)
            const bottomPad = paddingYFor(users[maxIdx].id)

            let overlayHeight = 0
            for (let i = minIdx; i <= maxIdx; i++) overlayHeight += rowHeightFor(users[i].id)

            const startOffsetMin = Math.max(0, Math.min(totalMin,
                ev.start.getHours() * 60 + ev.start.getMinutes() - dayStartHour.value * 60
            ))
            const endOffsetMin = Math.max(0, Math.min(totalMin,
                ev.end.getHours() * 60 + ev.end.getMinutes() - dayStartHour.value * 60
            ))

            return {
                id: ev.id,
                color: ev.color || '#3b82f6',
                top: rowTops[minIdx] + topPad,
                height: overlayHeight - topPad - bottomPad,
                leftPct: (dayIdx + startOffsetMin / totalMin) / 7 * 100,
                widthPct: (endOffsetMin - startOffsetMin) / totalMin / 7 * 100,
            }
        })
        .filter(Boolean)
})

function toggleAllDay() {
    allDayCollapsed.value = !allDayCollapsed.value
}

function rowHeightFor(userId) {
    return collapsedUsers.value.has(userId) ? COLLAPSED_ROW_H : props.rowHeight
}

function paddingYFor(userId) {
    return collapsedUsers.value.has(userId) ? 4 : props.eventPaddingY
}

function toggleUserRow(userId) {
    const next = new Set(collapsedUsers.value)
    next.has(userId) ? next.delete(userId) : next.add(userId)
    collapsedUsers.value = next
}

const allRowsCollapsed = computed(() =>
    visibleUsers.value.length > 0 && visibleUsers.value.every(u => collapsedUsers.value.has(u.id))
)

function toggleAllRows() {
    collapsedUsers.value = allRowsCollapsed.value
        ? new Set()
        : new Set(visibleUsers.value.map(u => u.id))
}

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

function startOfWeek(date) {
    return dayjs(date).startOf('isoWeek').toDate()
}

function dayLabel(date) {
    const days = ['ZO', 'MA', 'DI', 'WO', 'DO', 'VR', 'ZA']
    return `${days[date.getDay()]} ${dayjs(date).format('DD-MM')}`
}

function isToday(date) {
    return dayjs(date).isSame(dayjs(), 'day')
}

const nowOffsetPercent = ref(null)

function updateNow() {
    const t = new Date()
    const minutes = t.getHours() * 60 + t.getMinutes()
    const startMin = dayStartHour.value * 60
    const endMin = dayEndHour.value * 60
    if (minutes < startMin || minutes > endMin) {
        nowOffsetPercent.value = null
        return
    }
    nowOffsetPercent.value = ((minutes - startMin) / (endMin - startMin)) * 100
}

let nowInterval = null
onMounted(() => {
    updateNow()
    nowInterval = setInterval(updateNow, 60_000)
    fetchEvents()
    fetchUnavailabilities()
    nextTick(() => scrollToWorkdayStart())
})
onUnmounted(() => {
    if (nowInterval) clearInterval(nowInterval)
})

watch([dayStartHour, dayEndHour], () => updateNow())
watch(weekStart, () => {
    fetchEvents()
    fetchUnavailabilities()
})

function shiftWeek(direction) {
    weekStart.value = dayjs(weekStart.value).add(direction * 7, 'day').toDate()
}

function goToday() {
    const today = new Date()
    weekStart.value = startOfWeek(today)
    nextTick(() => scrollToDate(today))
}

function scrollToDate(date) {
    const grid = gridScrollRef.value
    if (!grid) return
    const dayIndex = Math.round(dayjs(date).startOf('day').diff(dayjs(weekStart.value).startOf('day'), 'day', true))
    if (dayIndex < 0 || dayIndex > 6) return
    grid.scrollTo({ left: dayIndex * dayWidthPx.value, behavior: 'smooth' })
}

function scrollToWorkdayStart() {
    const grid = gridScrollRef.value
    if (!grid) return
    grid.scrollLeft = 0
}

function onGridScroll(e) {
    if (!sidebarScrollRef.value) return
    sidebarScrollRef.value.scrollTop = e.target.scrollTop
}

async function fetchEvents() {
    try {
        await axios.get('sanctum/csrf-cookie')
        const startParam = formatUtcDatetime(weekStart.value)
        const endParam = formatUtcDatetime(dayjs(weekStart.value).add(7, 'day').toDate())
        const response = await axios.get(
            `/api/events?start=${encodeURIComponent(startParam)}&end=${encodeURIComponent(endParam)}`
        )
        if (response.status !== 200) return
        events.value = response.data.map(ev => {
            const customer_id = ev.service_orders?.[0]?.customer_id ?? null
            return {
                id: ev.id,
                title: ev.event_type?.name || ev.name || 'Afspraak',
                name: ev.name,
                description: ev.description,
                status: ev.status,
                color: ev.event_type?.color || '#3b82f6',
                event_type_id: ev.event_type?.id,
                start: new Date(ev.start),
                end: new Date(ev.end),
                executing_user_ids: (ev.executing_users || []).map(u => u.id),
                executing_users: ev.executing_users || [],
                eventable_id: ev.service_orders?.[0]?.id ?? null,
                eventable_type: '\\App\\Models\\ServiceOrder',
                customer_id,
                customer_name: ev.service_orders?.[0]?.customer?.name || null,
                is_preliminary: ev.is_preliminary ?? false,
            }
        })
    } catch (e) {
        console.error('Failed to fetch events for planner', e)
    }
}

async function fetchUnavailabilities() {
    try {
        const startParam = formatUtcDatetime(weekStart.value)
        const endParam = formatUtcDatetime(dayjs(weekStart.value).add(7, 'day').toDate())
        const response = await axios.get(
            `/api/unavailabilities?start=${encodeURIComponent(startParam)}&end=${encodeURIComponent(endParam)}`
        )
        if (response.status === 200) {
            unavailabilities.value = response.data
        }
    } catch (e) {
        console.error('Failed to fetch unavailabilities', e)
    }
}

function isBlockedAtTime(userId, dayIso, startMin, endMin) {
    // startMin/endMin are relative to dayStartHour; convert to absolute minutes from midnight
    const absStart = startMin + dayStartHour.value * 60
    const absEnd = endMin + dayStartHour.value * 60
    return unavailabilities.value.some(b => {
        if (b.user_id !== userId || b.date !== dayIso) return false
        if (b.start_time === null) return true // full day holiday
        const [sh, sm] = b.start_time.split(':').map(Number)
        const [eh, em] = b.end_time.split(':').map(Number)
        return absStart < eh * 60 + em && absEnd > sh * 60 + sm
    })
}

function getBlockOverlays(userId, dayIso) {
    const totalMin = (dayEndHour.value - dayStartHour.value) * 60
    return unavailabilities.value
        .filter(b => b.user_id === userId && b.date === dayIso)
        .map(b => {
            if (b.start_time === null) {
                return { left: 0, width: 100, label: b.label }
            }
            const [sh, sm] = b.start_time.split(':').map(Number)
            const [eh, em] = b.end_time.split(':').map(Number)
            const offsetStart = sh * 60 + sm - dayStartHour.value * 60
            const offsetEnd = eh * 60 + em - dayStartHour.value * 60
            const clampedStart = Math.max(0, offsetStart)
            const clampedEnd = Math.min(totalMin, offsetEnd)
            return {
                left: (clampedStart / totalMin) * 100,
                width: Math.max(0, ((clampedEnd - clampedStart) / totalMin) * 100),
                label: b.label,
            }
        })
}

function eventsFor(userId, dayIso) {
    return events.value.filter(ev => {
        if (!ev.executing_user_ids.includes(userId)) return false
        const evDayIso = formatLocalDateAsISO(ev.start)
        return evDayIso === dayIso
    })
}

function userHoursLabel(userId) {
    let mins = 0
    for (const ev of events.value) {
        if (!ev.executing_user_ids.includes(userId)) continue
        mins += Math.max(0, (ev.end - ev.start) / 60000)
    }
    const h = Math.floor(mins / 60)
    const m = Math.round(mins % 60)
    return `${h}u ${String(m).padStart(2, '0')}m deze week`
}

function snapMinutes(min) {
    return Math.round(min / slotMinutes.value) * slotMinutes.value
}

function minutesFromDayStart(date) {
    return date.getHours() * 60 + date.getMinutes() - dayStartHour.value * 60
}

function formatTimeFromMinutes(min) {
    const h = Math.floor((dayStartHour.value * 60 + min) / 60)
    const m = (dayStartHour.value * 60 + min) % 60
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
}

function formatTimeFromDate(date) {
    return nlTime(date)
}

function findCellElement(target) {
    let el = target
    while (el && el !== document.body) {
        if (el.dataset && el.dataset.userId && el.dataset.dayIso) {
            return el
        }
        el = el.parentElement
    }
    return null
}

function cellInfoFromPoint(clientX, clientY) {
    const el = document.elementFromPoint(clientX, clientY)
    const cell = findCellElement(el)
    if (!cell) return null
    const rect = cell.getBoundingClientRect()
    const xPct = (clientX - rect.left) / rect.width
    const totalMin = (dayEndHour.value - dayStartHour.value) * 60
    const minutes = Math.max(0, Math.min(totalMin, Math.round(xPct * totalMin)))
    const userId = parseInt(cell.dataset.userId, 10)
    return { cell, rect, userId, dayIso: cell.dataset.dayIso, minutes, totalMin }
}

function onCellPointerDown(e, user, day) {
    if (e.target.closest('[data-planner-event]')) return
    if (e.button !== 0) return
    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (!info) return
    if (isBlockedAtTime(user.id, day.iso, snapMinutes(info.minutes), snapMinutes(info.minutes) + slotMinutes.value)) return
    const startMin = snapMinutes(info.minutes)
    selectRect.value = {
        userId: user.id,
        dayIso: day.iso,
        startMinutes: startMin,
        endMinutes: startMin + slotMinutes.value,
        left: (startMin / info.totalMin) * 100,
        width: (slotMinutes.value / info.totalMin) * 100,
        cellRect: info.rect,
        totalMin: info.totalMin,
    }
    drag.value = { mode: 'select', user, day }
    e.preventDefault()
}

function onEventPointerDown(e, ev, user) {
    if (e.button !== 0) return
    if (!canEditEvent(ev)) return
    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (!info) return
    const startMin = minutesFromDayStart(ev.start)
    drag.value = {
        mode: 'move',
        eventId: ev.id,
        originalEvent: ev,
        cursorOffsetMinutes: info.minutes - startMin,
        durationMinutes: (ev.end - ev.start) / 60000,
        previewStart: new Date(ev.start),
        previewEnd: new Date(ev.end),
        previewUserId: user.id,
        ghostRect: null,
        isLocked: ev.executing_user_ids.length > 1,
    }
    updateGhost(e.clientX, e.clientY)
    e.preventDefault()
    e.stopPropagation()
}

function onResizePointerDown(e, ev, user, edge) {
    if (e.button !== 0) return
    if (!canEditEvent(ev)) return
    drag.value = {
        mode: 'resize',
        edge,
        eventId: ev.id,
        originalEvent: ev,
        previewStart: new Date(ev.start),
        previewEnd: new Date(ev.end),
        previewUserId: user.id,
    }
    updateGhost(e.clientX, e.clientY)
    e.preventDefault()
    e.stopPropagation()
}

function dateFromDayIsoAndMinutes(dayIso, minutes) {
    return dayjs(dayIso)
        .startOf('day')
        .add(dayStartHour.value * 60 + minutes, 'minute')
        .toDate()
}

function onWindowPointerMove(e) {
    if (!drag.value.mode) return
    if (drag.value.mode === 'select') {
        const info = cellInfoFromPoint(e.clientX, e.clientY)
        if (!info) return
        if (info.userId !== selectRect.value.userId || info.dayIso !== selectRect.value.dayIso) return
        const rawEnd = snapMinutes(info.minutes)
        const start = selectRect.value.startMinutes
        const end = Math.max(start + slotMinutes.value, rawEnd)
        selectRect.value.endMinutes = end
        selectRect.value.left = (Math.min(start, end) / info.totalMin) * 100
        selectRect.value.width = (Math.abs(end - start) / info.totalMin) * 100
        return
    }
    if (drag.value.mode === 'move' || drag.value.mode === 'resize') {
        updateGhost(e.clientX, e.clientY)
    }
}

function updateGhost(clientX, clientY) {
    const info = cellInfoFromPoint(clientX, clientY)
    if (!info) {
        dragGhost.value = null
        return
    }
    const ev = drag.value.originalEvent
    let previewStart, previewEnd, previewUserId, dayIso = info.dayIso

    if (drag.value.mode === 'move') {
        const rawStartMin = snapMinutes(info.minutes - drag.value.cursorOffsetMinutes)
        const totalMin = info.totalMin
        const dur = drag.value.durationMinutes
        const startMin = Math.max(0, Math.min(totalMin - dur, rawStartMin))
        previewStart = dateFromDayIsoAndMinutes(info.dayIso, startMin)
        previewEnd = new Date(previewStart.getTime() + dur * 60000)
        previewUserId = drag.value.isLocked ? ev.executing_user_ids[0] : info.userId
    } else {
        const minutes = snapMinutes(info.minutes)
        if (drag.value.edge === 'start') {
            previewEnd = new Date(ev.end)
            const newStart = dateFromDayIsoAndMinutes(formatLocalDateAsISO(ev.start), minutes)
            previewStart = newStart < previewEnd ? newStart : new Date(previewEnd.getTime() - slotMinutes.value * 60000)
        } else {
            previewStart = new Date(ev.start)
            const newEnd = dateFromDayIsoAndMinutes(formatLocalDateAsISO(ev.start), minutes)
            previewEnd = newEnd > previewStart ? newEnd : new Date(previewStart.getTime() + slotMinutes.value * 60000)
        }
        previewUserId = drag.value.previewUserId
        dayIso = formatLocalDateAsISO(ev.start)
    }

    drag.value.previewStart = previewStart
    drag.value.previewEnd = previewEnd
    drag.value.previewUserId = previewUserId
    drag.value.previewDayIso = dayIso

    const targetUser = props.plannableUsers.find(u => u.id === previewUserId)
    const targetCell = document.querySelector(
        `[data-user-id="${previewUserId}"][data-day-iso="${dayIso}"]`
    )
    if (!targetCell || !bodyRef.value) {
        dragGhost.value = null
        return
    }
    const cellRect = targetCell.getBoundingClientRect()
    const bodyRect = bodyRef.value.getBoundingClientRect()
    const startMin = minutesFromDayStart(previewStart)
    const totalMin = info.totalMin
    const padY = paddingYFor(previewUserId)
    const leftPx = (cellRect.left - bodyRect.left) + (startMin / totalMin) * cellRect.width
    const topPx = cellRect.top - bodyRect.top
    const widthPx = ((previewEnd - previewStart) / 60000 / totalMin) * cellRect.width

    dragGhost.value = {
        title: ev.title,
        start: previewStart,
        end: previewEnd,
        userName: (drag.value.mode === 'move' && targetUser && targetUser.id !== ev.executing_user_ids[0]) ? targetUser.name : null,
        style: {
            left: leftPx + 'px',
            top: (topPx + padY) + 'px',
            width: Math.max(40, widthPx) + 'px',
            height: (rowHeightFor(previewUserId) - 2 * padY) + 'px',
            borderColor: ev.color || '#3b82f6',
            color: ev.color || '#3b82f6',
        },
    }
}

async function onWindowPointerUp() {
    if (!drag.value.mode) return
    const mode = drag.value.mode
    if (mode === 'select') {
        const sel = selectRect.value
        selectRect.value = null
        drag.value = { eventId: null, mode: null }
        if (!sel) return
        const start = dateFromDayIsoAndMinutes(sel.dayIso, Math.min(sel.startMinutes, sel.endMinutes))
        const end = dateFromDayIsoAndMinutes(sel.dayIso, Math.max(sel.startMinutes, sel.endMinutes))
        openCreate({ start, end, userId: sel.userId })
        return
    }
    if (mode === 'move' || mode === 'resize') {
        const ev = drag.value.originalEvent
        const previewStart = drag.value.previewStart
        const previewEnd = drag.value.previewEnd
        const previewUserId = drag.value.previewUserId
        const movedTime = previewStart.getTime() !== ev.start.getTime() ||
            previewEnd.getTime() !== ev.end.getTime()
        const movedUser = !drag.value.isLocked && mode === 'move' &&
            !ev.executing_user_ids.includes(previewUserId)
        dragGhost.value = null
        drag.value = { eventId: null, mode: null }
        if (movedTime || movedUser) {
            suppressClickUntil = Date.now() + 300
            await persistEventChange(ev, previewStart, previewEnd, movedUser ? previewUserId : null)
        }
    }
}

function handleEventClick(ev) {
    if (Date.now() < suppressClickUntil) return
    if (!canEditEvent(ev)) return
    openEdit(ev)
}

function onEventContextMenu(e, ev) {
    drag.value = { eventId: null, mode: null }
    dragGhost.value = null
    injectTypeColorStyles()
    const items = []
    if (canEditEvent(ev)) {
        items.push({
            label: 'Wijzig type',
            children: props.eventTypes.map(t => ({
                label: ev.event_type_id === t.id ? `${t.name}  ✓` : t.name,
                customClass: 'planner-cm-type-' + t.id,
                onClick: () => changeEventType(ev, t),
            })),
        })
        items.push({
            label: `Monteurs (${ev.executing_user_ids.length})`,
            children: props.allUsers.map(u => {
                const assigned = ev.executing_user_ids.includes(u.id)
                const isLast = assigned && ev.executing_user_ids.length === 1
                return {
                    label: assigned ? `${u.name}  ✓` : u.name,
                    disabled: isLast,
                    onClick: () => toggleExecutingUser(ev, u),
                }
            }),
        })
        items.push({ label: 'Bewerken…', onClick: () => openEdit(ev) })
        items.push({
            label: ev.is_preliminary ? 'Markeer als definitief' : 'Markeer als voorlopig',
            divided: true,
            onClick: () => togglePreliminary(ev),
        })
    }
    if (ev.eventable_id) {
        items.push({
            label: `Open werkbon #${ev.eventable_id}`,
            divided: items.length > 0,
            onClick: () => router.visit(`/serviceorders/${ev.eventable_id}`),
        })
        items.push({
            label: 'Stuur afspraakbevestiging',
            onClick: () => sendAppointmentConfirmation(ev),
        })
    }
    if (ev.customer_id && hasPermission('customer.read')) {
        items.push({
            label: `Open klant${ev.customer_name ? ` — ${ev.customer_name}` : ''}`,
            onClick: () => router.visit(`/customers/${ev.customer_id}`),
        })
    }
    if (canDeleteEvent(ev)) {
        items.push({ label: 'Verwijderen', divided: items.length > 0, onClick: () => deleteEvent(ev) })
    }
    ContextMenu.showContextMenu({
        x: e.clientX,
        y: e.clientY,
        items,
    })
}

let typeStyleEl = null
function injectTypeColorStyles() {
    if (typeStyleEl) return
    typeStyleEl = document.createElement('style')
    typeStyleEl.textContent = props.eventTypes.map(t =>
        `.mx-context-menu-item.planner-cm-type-${t.id} .label::before { content: "● "; color: ${t.color || '#3b82f6'}; font-weight: 700; }`
    ).join('\n')
    document.head.appendChild(typeStyleEl)
}

async function toggleExecutingUser(ev, user) {
    const wasAssigned = ev.executing_user_ids.includes(user.id)
    const original = {
        ids: [...ev.executing_user_ids],
        users: [...ev.executing_users],
    }
    const next_ids = wasAssigned
        ? ev.executing_user_ids.filter(id => id !== user.id)
        : [...ev.executing_user_ids, user.id]
    if (next_ids.length === 0) return
    ev.executing_user_ids = next_ids
    ev.executing_users = wasAssigned
        ? ev.executing_users.filter(u => u.id !== user.id)
        : [...ev.executing_users, { id: user.id, name: user.name, avatar: user.avatar }]
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.put(`/api/events/${ev.id}`, { executing_user_ids: next_ids })
        if (r.status !== 200) throw new Error('bad response')
        page.props.flash.success = wasAssigned
            ? `${user.name} verwijderd van afspraak`
            : `${user.name} toegevoegd aan afspraak`
    } catch (e) {
        console.error('Failed to update executing users', e)
        ev.executing_user_ids = original.ids
        ev.executing_users = original.users
        page.props.flash.error = e.response?.data?.message || 'Kon monteurs niet bijwerken'
    }
}

async function togglePreliminary(ev) {
    const original = ev.is_preliminary
    ev.is_preliminary = !original
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.put(`/api/events/${ev.id}`, { is_preliminary: ev.is_preliminary })
        if (r.status !== 200) throw new Error('bad response')
        page.props.flash.success = ev.is_preliminary ? 'Afspraak gemarkeerd als voorlopig' : 'Afspraak gemarkeerd als definitief'
    } catch (e) {
        console.error('Failed to toggle preliminary', e)
        ev.is_preliminary = original
        page.props.flash.error = e.response?.data?.message || 'Kon voorlopig-status niet wijzigen'
    }
}

async function changeEventType(ev, type) {
    const original = { event_type_id: ev.event_type_id, title: ev.title, color: ev.color }
    ev.event_type_id = type.id
    ev.title = type.name
    ev.color = type.color || ev.color
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.put(`/api/events/${ev.id}`, { event_type_id: type.id })
        if (r.status !== 200) throw new Error('bad response')
        page.props.flash.success = `Afspraaktype gewijzigd naar "${type.name}"`
    } catch (e) {
        console.error('Failed to change event type', e)
        ev.event_type_id = original.event_type_id
        ev.title = original.title
        ev.color = original.color
        page.props.flash.error = e.response?.data?.message || 'Kon afspraaktype niet wijzigen'
    }
}

async function sendAppointmentConfirmation(ev) {
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.post(`/api/events/${ev.id}/send-confirmation`)
        page.props.flash.success = r.data.message
    } catch (e) {
        page.props.flash.error = e.response?.data?.message || 'Kon bevestiging niet verzenden'
    }
}

async function deleteEvent(ev) {
    if (!confirm(`Weet je zeker dat je afspraak #${ev.id} wilt verwijderen?`)) return
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.delete(`/api/events/${ev.id}`)
        if (r.status !== 204) throw new Error('bad response')
        events.value = events.value.filter(x => x.id !== ev.id)
        if (ev.eventable_id && ev.eventable_type === '\\App\\Models\\ServiceOrder') {
            emit('service-order-unplanned', ev.eventable_id)
        }
        page.props.flash.success = 'Afspraak verwijderd'
    } catch (e) {
        console.error('Failed to delete event', e)
        page.props.flash.error = e.response?.data?.message || 'Kon afspraak niet verwijderen'
    }
}

async function persistEventChange(ev, newStart, newEnd, replaceWithUserId) {
    const original = { start: ev.start, end: ev.end, executing_user_ids: [...ev.executing_user_ids] }
    ev.start = newStart
    ev.end = newEnd
    if (replaceWithUserId) {
        ev.executing_user_ids = [replaceWithUserId]
        ev.executing_users = props.plannableUsers
            .filter(u => u.id === replaceWithUserId)
            .map(u => ({ id: u.id, name: u.name, avatar: u.avatar }))
    }
    try {
        const payload = {
            start: formatUtcDatetime(newStart).slice(0, 16),
            end: formatUtcDatetime(newEnd).slice(0, 16),
        }
        if (replaceWithUserId) {
            payload.executing_user_ids = ev.executing_user_ids
        }
        await axios.get('sanctum/csrf-cookie')
        const response = await axios.put(`/api/events/${ev.id}`, payload)
        if (response.status !== 200) throw new Error('bad response')
        page.props.flash.success = replaceWithUserId
            ? 'Afspraak verplaatst naar andere monteur'
            : 'Afspraak bijgewerkt'
    } catch (e) {
        console.error('Failed to update event', e)
        ev.start = original.start
        ev.end = original.end
        ev.executing_user_ids = original.executing_user_ids
        page.props.flash.error = e.response?.data?.message || 'Kon afspraak niet bijwerken'
        fetchEvents()
    }
}

const DROP_DURATION_MIN = 120

/** Snapped, day-clamped start minute for an incoming drop of the given duration. */
function dropStartMinutes(info, durationMin) {
    const snapped = snapMinutes(info.minutes)
    const maxStart = Math.max(0, info.totalMin - durationMin)
    return Math.max(0, Math.min(maxStart, snapped))
}

function onDragOver(e) {
    if (!(e.dataTransfer && e.dataTransfer.types?.includes('application/x-planner-payload'))) return
    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (info) {
        const startMin = dropStartMinutes(info, DROP_DURATION_MIN)
        if (isBlockedAtTime(info.userId, info.dayIso, startMin, startMin + DROP_DURATION_MIN)) {
            e.dataTransfer.dropEffect = 'none'
            dragGhost.value = null
            return
        }
    }
    e.dataTransfer.dropEffect = 'copy'
    updateExternalDropGhost(e.clientX, e.clientY)
}

function onGridDragLeave(e) {
    // Only clear when the cursor actually leaves the grid, not when crossing child cells.
    if (!e.currentTarget.contains(e.relatedTarget)) {
        dragGhost.value = null
    }
}

function updateExternalDropGhost(clientX, clientY) {
    const info = cellInfoFromPoint(clientX, clientY)
    if (!info || !bodyRef.value) {
        dragGhost.value = null
        return
    }
    const startMin = dropStartMinutes(info, DROP_DURATION_MIN)
    const start = dateFromDayIsoAndMinutes(info.dayIso, startMin)
    const end = new Date(start.getTime() + DROP_DURATION_MIN * 60000)
    const targetUser = props.plannableUsers.find(u => u.id === info.userId)
    const targetCell = document.querySelector(
        `[data-user-id="${info.userId}"][data-day-iso="${info.dayIso}"]`
    )
    if (!targetCell) {
        dragGhost.value = null
        return
    }
    const cellRect = targetCell.getBoundingClientRect()
    const bodyRect = bodyRef.value.getBoundingClientRect()
    const padY = paddingYFor(info.userId)
    const leftPx = (cellRect.left - bodyRect.left) + (startMin / info.totalMin) * cellRect.width
    const topPx = cellRect.top - bodyRect.top
    const widthPx = (DROP_DURATION_MIN / info.totalMin) * cellRect.width
    dragGhost.value = {
        title: 'Nieuwe afspraak (2 uur)',
        start,
        end,
        userName: targetUser?.name || null,
        style: {
            left: leftPx + 'px',
            top: (topPx + padY) + 'px',
            width: Math.max(40, widthPx) + 'px',
            height: (rowHeightFor(info.userId) - 2 * padY) + 'px',
            borderColor: '#2563ff',
            color: '#2563ff',
        },
    }
}

function onProjectServiceOrderDragStart(e, so) {
    setServiceOrderDragData(e, so)
    e.currentTarget.classList.add('opacity-40')
}

function onProjectServiceOrderDragEnd(e) {
    e.currentTarget.classList.remove('opacity-40')
}

function onExternalDrop(e, user, day) {
    dragGhost.value = null
    const raw = e.dataTransfer?.getData('application/x-planner-payload')
    if (!raw) return
    let payload
    try { payload = JSON.parse(raw) } catch { return }

    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (!info) return
    const duration = payload.duration_minutes || DROP_DURATION_MIN
    const startMin = dropStartMinutes(info, duration)
    if (isBlockedAtTime(user.id, day.iso, startMin, startMin + duration)) return
    const start = dateFromDayIsoAndMinutes(day.iso, startMin)
    const end = new Date(start.getTime() + duration * 60000)
    createEventFromDrop({ start, end, userId: user.id, payload })
}

async function createEventFromDrop({ start, end, userId, payload }) {
    if (!hasPermission('event.create')) {
        page.props.flash.error = 'Je hebt geen rechten om afspraken te maken'
        return
    }
    const eventTypeId = props.eventTypes[0]?.id
    if (!eventTypeId) {
        page.props.flash.error = 'Geen afspraaktype beschikbaar om in te plannen'
        return
    }
    const body = {
        event_type_id: eventTypeId,
        name: payload.name || '',
        description: payload.description || '',
        status: 'Gepland',
        start: formatUtcDatetime(start).slice(0, 16),
        end: formatUtcDatetime(end).slice(0, 16),
        eventable_type: payload.eventable_type || '\\App\\Models\\ServiceOrder',
        eventable_id: payload.eventable_id || null,
        executing_user_ids: [userId],
    }
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.post('/api/events', body)
        if (r.status !== 201) throw new Error('bad response')
        page.props.flash.success = 'Werkbon ingepland (2 uur)'
        if (body.eventable_id) emit('service-order-planned', body.eventable_id)
        fetchEvents()
    } catch (err) {
        console.error('Failed to create event from drop', err)
        page.props.flash.error = err.response?.data?.message || 'Kon werkbon niet inplannen'
    }
}

function openCreate(initial) {
    editingExistingEvent.value = false
    modalInitial.value = {
        id: null,
        event_type_id: props.eventTypes[0]?.id || '',
        name: initial.name || '',
        description: initial.description || '',
        status: props.eventStatusses[0]?.name || 'Gepland',
        start: initial.start,
        end: initial.end,
        eventable_type: initial.eventable_type || '\\App\\Models\\ServiceOrder',
        eventable_id: initial.eventable_id || '',
        customer_id: initial.customer_id || null,
        executing_user_ids: [initial.userId],
    }
    modalOpen.value = true
}

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

function closeModal() {
    modalOpen.value = false
    modalInitial.value = null
}

function onSaved() {
    closeModal()
    fetchEvents()
}
</script>
