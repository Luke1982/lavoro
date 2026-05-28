<template>
    <div class="flex flex-col h-full bg-white dark:bg-slate-900 text-gray-900 dark:text-slate-100"
        @pointermove="onWindowPointerMove" @pointerup="onWindowPointerUp">
        <!-- Top toolbar -->
        <div class="relative z-30 flex items-center px-4 py-3 border-b border-gray-200 dark:border-slate-800 gap-3 flex-wrap bg-white dark:bg-slate-900">
            <h1 class="text-xl font-bold pr-4">Planning</h1>
            <button class="rounded-md border border-gray-300 dark:border-slate-700 px-3 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-slate-800"
                @click="goToday">Vandaag</button>
            <button class="rounded-md border border-gray-300 dark:border-slate-700 p-1.5 hover:bg-gray-50 dark:hover:bg-slate-800"
                @click="shiftWeek(-1)" aria-label="Vorige week">
                <ChevronLeftIcon class="size-4" />
            </button>
            <button class="rounded-md border border-gray-300 dark:border-slate-700 p-1.5 hover:bg-gray-50 dark:hover:bg-slate-800"
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
                    class="rounded-md border border-gray-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm px-2 py-1">
                    <option v-for="h in 12" :key="h - 1" :value="h - 1">{{ String(h - 1).padStart(2, '0') }}:00</option>
                </select>
                <span class="text-xs">tot</span>
                <select v-model.number="dayEndHour"
                    class="rounded-md border border-gray-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm px-2 py-1">
                    <option v-for="h in 24" :key="h" :value="h">{{ String(h).padStart(2, '0') }}:00</option>
                </select>
            </div>
        </div>

        <div class="flex flex-1 min-h-0">
            <!-- Resource sidebar -->
            <div class="w-64 shrink-0 border-r border-gray-200 dark:border-slate-800 flex flex-col">
                <div class="border-b border-gray-200 dark:border-slate-800 px-4 flex items-end pb-2 text-xs text-gray-500 dark:text-slate-400"
                    :style="{ height: headerHeight + 'px' }">
                    Monteurs ({{ plannableUsers.length }})
                </div>
                <div class="flex-1 overflow-y-auto" ref="sidebarScrollRef">
                    <div v-for="(user, idx) in plannableUsers" :key="user.id"
                        :style="{ height: rowHeight + 'px' }"
                        class="flex items-center gap-3 px-4 border-b border-gray-100 dark:border-slate-800"
                        :class="idx % 2 === 1 ? 'bg-gray-50/40 dark:bg-slate-800/40' : ''">
                        <div class="h-10 w-10 rounded-full bg-gray-200 dark:bg-slate-700 flex items-center justify-center overflow-hidden text-xs font-semibold ring-1 ring-gray-300 dark:ring-slate-700">
                            <img v-if="user.avatar" :src="user.avatar" class="object-cover w-full h-full"
                                :alt="user.name" />
                            <span v-else>{{ initials(user.name) }}</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold truncate">{{ user.name }}</div>
                            <div class="text-xs text-gray-500 dark:text-slate-400">{{ userHoursLabel(user.id) }}</div>
                        </div>
                    </div>
                    <div v-if="plannableUsers.length === 0"
                        class="p-4 text-xs text-gray-500 dark:text-slate-400">
                        Geen inplanbare monteurs.
                        Schakel "Inplanbaar" in op een gebruiker via Gebruikers.
                    </div>
                </div>
            </div>

            <!-- Time grid -->
            <div class="flex-1 overflow-auto relative" ref="gridScrollRef" @scroll="onGridScroll">
                <!-- Headers (sticky) -->
                <div class="sticky top-0 z-20 bg-white dark:bg-slate-900">
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
                    <div class="grid border-b border-gray-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-[11px] text-gray-500 dark:text-slate-400"
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
                    <div v-for="(user, idx) in plannableUsers" :key="'row-' + user.id"
                        class="grid relative"
                        :style="{ gridTemplateColumns: dayGridTemplate, height: rowHeight + 'px' }"
                        :class="idx % 2 === 1 ? 'bg-gray-50/40 dark:bg-slate-800/40' : ''">

                        <div v-for="day in weekDays" :key="'cell-' + user.id + '-' + day.iso"
                            class="relative border-l border-b border-gray-200 dark:border-slate-800 first:border-l-0"
                            :data-user-id="user.id"
                            :data-day-iso="day.iso"
                            @pointerdown="onCellPointerDown($event, user, day)"
                            @dragover.prevent="onDragOver($event, user, day)"
                            @drop.prevent="onExternalDrop($event, user, day)">
                            <!-- Hour grid lines -->
                            <div class="absolute inset-0 grid pointer-events-none"
                                :style="{ gridTemplateColumns: `repeat(${hourCount}, minmax(0, 1fr))` }">
                                <div v-for="h in hourCount" :key="'hgl-' + user.id + '-' + day.iso + '-' + h"
                                    class="border-l border-gray-100 dark:border-slate-800/60 first:border-l-0" />
                            </div>

                            <!-- Now indicator -->
                            <div v-if="isToday(day.date) && nowOffsetPercent !== null"
                                class="absolute top-0 bottom-0 w-px bg-red-500/70 pointer-events-none z-10"
                                :style="{ left: nowOffsetPercent + '%' }">
                                <div class="absolute -top-1 -translate-x-1/2 size-2 rounded-full bg-red-500"></div>
                            </div>

                            <!-- Events for this user/day -->
                            <PlannerEvent v-for="ev in eventsFor(user.id, day.iso)" :key="ev.id + '-' + user.id"
                                :event="ev"
                                :user-id="user.id"
                                :day="day"
                                :slot-minutes="slotMinutes"
                                :day-start-hour="dayStartHour"
                                :day-end-hour="dayEndHour"
                                :row-height="rowHeight"
                                :event-padding-y="eventPaddingY"
                                :is-locked="ev.executing_user_ids.length > 1"
                                :is-being-dragged="drag.eventId === ev.id"
                                @click="handleEventClick(ev)"
                                @contextmenu="onEventContextMenu($event, ev)"
                                @pointerdown-on-event="onEventPointerDown($event, ev, user)"
                                @pointerdown-on-resize="onResizePointerDown($event, ev, user, $event.edge)" />

                            <!-- Live selection rectangle (click-drag-create) -->
                            <div v-if="selectRect && selectRect.userId === user.id && selectRect.dayIso === day.iso"
                                class="absolute top-1 bottom-1 bg-blue-500/30 border-2 border-dashed border-blue-500 rounded-md pointer-events-none"
                                :style="{ left: selectRect.left + '%', width: selectRect.width + '%' }">
                                <div class="absolute -top-5 left-1 text-[10px] font-semibold text-blue-700 dark:text-blue-300 bg-white dark:bg-slate-900 rounded px-1">
                                    {{ formatTimeFromMinutes(selectRect.startMinutes) }} –
                                    {{ formatTimeFromMinutes(selectRect.endMinutes) }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Drag ghost (floats above grid) -->
                    <div v-if="dragGhost"
                        class="absolute pointer-events-none rounded-md border-2 border-dashed bg-white/90 dark:bg-slate-800/90 shadow-lg z-30 px-2 py-1 text-xs"
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
        <EventEditModal v-if="modalOpen"
            :event-types="eventTypes"
            :event-statusses="eventStatusses"
            :all-customers="allCustomers"
            :all-service-orders="allServiceOrders"
            :all-users="allUsers"
            :initial="modalInitial"
            :editing-existing="editingExistingEvent"
            @close="closeModal"
            @saved="onSaved" />
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import axios from 'axios'
import { ChevronLeftIcon, ChevronRightIcon, Squares2X2Icon } from '@heroicons/vue/24/outline'
import { initials, formatLocalDateAsISO, formatUtcDatetime, nlTime, hasPermission } from '@/Utilities/Utilities'
import PlannerEvent from './Planner/PlannerEvent.vue'
import EventEditModal from './Planner/EventEditModal.vue'
import SelectMenuComponent from './UI/SelectMenuComponent.vue'
import ContextMenu from '@imengyu/vue3-context-menu'

const props = defineProps({
    eventTypes: { type: Array, default: () => [] },
    allCustomers: { type: Array, default: () => [] },
    allServiceOrders: { type: Array, default: () => [] },
    eventStatusses: { type: Array, default: () => [] },
    allUsers: { type: Array, default: () => [] },
    plannableUsers: { type: Array, default: () => [] },
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

const page = usePage()

const slotMinutes = ref(props.defaultSlotMinutes)
const dayStartHour = ref(props.defaultDayStartHour)
const dayEndHour = ref(props.defaultDayEndHour)

const events = ref([])
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

const weekDays = computed(() => {
    const out = []
    for (let i = 0; i < 7; i++) {
        const d = new Date(weekStart.value)
        d.setDate(d.getDate() + i)
        out.push({ date: d, iso: formatLocalDateAsISO(d) })
    }
    return out
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
    const d = new Date(date)
    d.setHours(0, 0, 0, 0)
    const day = d.getDay()
    const diff = (day === 0 ? -6 : 1 - day)
    d.setDate(d.getDate() + diff)
    return d
}

function dayLabel(date) {
    const days = ['ZO', 'MA', 'DI', 'WO', 'DO', 'VR', 'ZA']
    const dd = String(date.getDate()).padStart(2, '0')
    const mm = String(date.getMonth() + 1).padStart(2, '0')
    return `${days[date.getDay()]} ${dd}-${mm}`
}

function isToday(date) {
    const t = new Date()
    return date.getFullYear() === t.getFullYear() &&
        date.getMonth() === t.getMonth() &&
        date.getDate() === t.getDate()
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
    nextTick(() => scrollToWorkdayStart())
})
onUnmounted(() => {
    if (nowInterval) clearInterval(nowInterval)
})

watch([dayStartHour, dayEndHour], () => updateNow())
watch(weekStart, () => fetchEvents())

function shiftWeek(direction) {
    const d = new Date(weekStart.value)
    d.setDate(d.getDate() + direction * 7)
    weekStart.value = d
}

function goToday() {
    weekStart.value = startOfWeek(new Date())
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
        const start = new Date(weekStart.value)
        const end = new Date(weekStart.value)
        end.setDate(end.getDate() + 7)
        const startParam = formatUtcDatetime(start)
        const endParam = formatUtcDatetime(end)
        const response = await axios.get(
            `/api/events?start=${encodeURIComponent(startParam)}&end=${encodeURIComponent(endParam)}`
        )
        if (response.status !== 200) return
        events.value = response.data.map(ev => {
            const customer_id = ev.service_orders?.[0]?.customer_id ?? null
            const customer = customer_id ? props.allCustomers.find(c => c.id === customer_id) : null
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
                customer_name: customer?.name || null,
            }
        })
    } catch (e) {
        console.error('Failed to fetch events for planner', e)
    }
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
    const [y, m, d] = dayIso.split('-').map(n => parseInt(n, 10))
    const totalMin = dayStartHour.value * 60 + minutes
    const hours = Math.floor(totalMin / 60)
    const mins = totalMin % 60
    return new Date(y, m - 1, d, hours, mins, 0, 0)
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
            top: (topPx + props.eventPaddingY) + 'px',
            width: Math.max(40, widthPx) + 'px',
            height: (props.rowHeight - 2 * props.eventPaddingY) + 'px',
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
    openEdit(ev)
}

function onEventContextMenu(e, ev) {
    drag.value = { eventId: null, mode: null }
    dragGhost.value = null
    injectTypeColorStyles()
    const items = [
        {
            label: 'Wijzig type',
            children: props.eventTypes.map(t => ({
                label: ev.event_type_id === t.id ? `${t.name}  ✓` : t.name,
                customClass: 'planner-cm-type-' + t.id,
                onClick: () => changeEventType(ev, t),
            })),
        },
        { label: 'Bewerken…', onClick: () => openEdit(ev) },
    ]
    if (ev.eventable_id) {
        items.push({
            label: `Open werkbon #${ev.eventable_id}`,
            divided: true,
            onClick: () => router.visit(`/serviceorders/${ev.eventable_id}`),
        })
    }
    if (ev.customer_id && hasPermission('customer.read')) {
        const customer = props.allCustomers.find(c => c.id === ev.customer_id)
        items.push({
            label: `Open klant${customer?.name ? ` — ${customer.name}` : ''}`,
            onClick: () => router.visit(`/customers/${ev.customer_id}`),
        })
    }
    items.push({ label: 'Verwijderen', divided: true, onClick: () => deleteEvent(ev) })
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

async function deleteEvent(ev) {
    if (!confirm(`Weet je zeker dat je afspraak #${ev.id} wilt verwijderen?`)) return
    try {
        await axios.get('sanctum/csrf-cookie')
        const r = await axios.delete(`/api/events/${ev.id}`)
        if (r.status !== 204) throw new Error('bad response')
        events.value = events.value.filter(x => x.id !== ev.id)
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

function onDragOver(e) {
    if (e.dataTransfer && e.dataTransfer.types?.includes('application/x-planner-payload')) {
        e.dataTransfer.dropEffect = 'copy'
    }
}

function onExternalDrop(e, user, day) {
    const raw = e.dataTransfer?.getData('application/x-planner-payload')
    if (!raw) return
    let payload
    try { payload = JSON.parse(raw) } catch { return }

    const info = cellInfoFromPoint(e.clientX, e.clientY)
    if (!info) return
    const startMin = snapMinutes(info.minutes)
    const duration = payload.duration_minutes || 60
    const start = dateFromDayIsoAndMinutes(day.iso, startMin)
    const end = new Date(start.getTime() + duration * 60000)
    openCreate({
        start,
        end,
        userId: user.id,
        name: payload.name || '',
        description: payload.description || '',
        eventable_type: payload.eventable_type || '\\App\\Models\\ServiceOrder',
        eventable_id: payload.eventable_id || null,
        customer_id: payload.customer_id || null,
    })
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
        executing_user_ids: [...ev.executing_user_ids],
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
