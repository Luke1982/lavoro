<template>
  <div class="relative h-full">
    <div ref="scrollEl" class="h-full overflow-auto overscroll-x-contain" @touchstart.passive="onTouchStart"
        @touchmove.passive="onTouchMove" @touchend.passive="onTouchEnd" @touchcancel.passive="onTouchEnd">
        <div class="min-w-max">
            <!-- Mechanic lanes header -->
            <div
                class="sticky top-0 z-30 flex bg-white dark:bg-slate-900 border-b border-gray-200 dark:border-slate-800">
                <div class="sticky left-0 z-10 w-10 shrink-0 bg-white dark:bg-slate-900" />
                <div v-for="user in plannableUsers" :key="'head-' + user.id"
                    class="shrink-0 flex items-center gap-1 px-1.5 py-2 border-l border-gray-100 dark:border-slate-800"
                    :style="{ width: LANE_WIDTH + 'px' }">
                    <div
                        class="size-5 shrink-0 rounded-full bg-gray-200 dark:bg-slate-700 flex items-center justify-center overflow-hidden text-[9px] font-semibold">
                        <img v-if="user.avatar" :src="user.avatar" class="w-full h-full object-cover" :alt="user.name" />
                        <span v-else>{{ initials(user.name) }}</span>
                    </div>
                    <span class="text-xs font-medium truncate">{{ user.name }}</span>
                </div>
            </div>

            <template v-for="day in weekDays" :key="day.iso">
                <!-- Day header: the band scrolls with the lanes, the label stays pinned -->
                <div
                    class="sticky z-20 border-b border-gray-200 dark:border-slate-700 bg-gray-50/95 dark:bg-slate-800/95 backdrop-blur-sm"
                    :style="{ top: HEADER_HEIGHT + 'px' }">
                    <span
                        class="sticky left-0 inline-block px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">
                        {{ nlDayName(day.iso) }}
                        <span class="font-normal normal-case">{{ dayjs(day.iso).format('D MMM') }}</span>
                    </span>
                </div>

                <div class="flex">
                    <!-- Hour gutter -->
                    <div class="sticky left-0 z-10 w-10 shrink-0 bg-white dark:bg-slate-900 border-r border-gray-100 dark:border-slate-800"
                        :style="{ height: dayHeightFor(day.iso) + 'px' }">
                        <div v-for="hour in hoursFor(day.iso)" :key="'hr-' + day.iso + '-' + hour" class="relative"
                            :style="{ height: HOUR_HEIGHT + 'px' }">
                            <span
                                class="absolute -top-1 right-1 text-[10px] tabular-nums text-gray-400 dark:text-slate-500">
                                {{ String(hour).padStart(2, '0') }}
                            </span>
                        </div>
                    </div>

                    <div v-for="user in plannableUsers" :key="'lane-' + day.iso + '-' + user.id"
                        class="relative shrink-0 border-l border-b border-gray-100 dark:border-slate-800"
                        :style="{ width: LANE_WIDTH + 'px', height: dayHeightFor(day.iso) + 'px' }">
                        <!-- Hour grid lines -->
                        <div class="absolute inset-0 pointer-events-none">
                            <div v-for="hour in hoursFor(day.iso)" :key="'gl-' + day.iso + '-' + user.id + '-' + hour"
                                class="border-t border-gray-100 dark:border-slate-800/60 first:border-t-0"
                                :style="{ height: HOUR_HEIGHT + 'px' }" />
                        </div>

                        <!-- Outside the bookable window: dimmed, never offered as room -->
                        <div v-for="(fringe, fi) in offHourFringesFor(day.iso)" :key="'off-' + day.iso + '-' + user.id + '-' + fi"
                            class="absolute inset-x-0 bg-gray-50/70 dark:bg-slate-800/40 pointer-events-none"
                            :style="positionFor(fringe, day.iso)" />

                        <!-- Free room and blocked time -->
                        <button v-for="(segment, si) in segmentsFor(user.id, day.iso)"
                            :key="'seg-' + day.iso + '-' + user.id + '-' + si" type="button"
                            class="absolute inset-x-0.5 rounded-md overflow-hidden flex items-start justify-center"
                            :class="segment.kind === 'free'
                                ? 'border border-dashed border-emerald-300 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-950/20'
                                : 'border border-gray-200 dark:border-slate-700'"
                            :style="{
                                ...positionFor(segment, day.iso),
                                ...(segment.kind === 'blocked' ? { background: UNAVAILABLE_PATTERN } : {}),
                                cursor: segmentTappable(segment) ? 'pointer' : 'default',
                            }" :disabled="!segmentTappable(segment)"
                            @click="$emit('slot-tap', { dayIso: day.iso, userId: user.id, ...segment })">
                            <span class="px-1 pt-0.5 text-[9px] font-medium leading-tight truncate"
                                :class="segment.kind === 'free'
                                    ? 'text-emerald-700 dark:text-emerald-400'
                                    : 'text-gray-500 dark:text-slate-400'">
                                {{ segment.kind === 'free'
                                    ? formatDurationLabel(segment.endMin - segment.startMin)
                                    : (segment.label || 'Niet beschikbaar') }}
                            </span>
                        </button>

                        <!-- Appointments -->
                        <button v-for="ev in eventsFor(user.id, day.iso)"
                            :key="'ev-' + day.iso + '-' + user.id + '-' + ev.id" type="button"
                            class="absolute inset-x-0.5 rounded-md border overflow-hidden text-left z-10" :style="{
                                ...positionFor(eventMinutesFor(ev, user.id, day.iso), day.iso),
                                backgroundColor: `color-mix(in srgb, ${ev.color || DEFAULT_EVENT_COLOR} 22%, transparent)`,
                                borderColor: `color-mix(in srgb, ${ev.color || DEFAULT_EVENT_COLOR} 55%, transparent)`,
                            }" @click="$emit('event-tap', ev)">
                            <div class="px-1 py-0.5 leading-tight">
                                <div class="text-[9px] font-semibold text-gray-700 dark:text-slate-100 truncate">
                                    {{ ev.eventable_id ? formatWbNumber(ev.eventable_id) : (ev.name || 'Afspraak') }}
                                </div>
                                <div v-if="ev.customer_name" class="text-[9px] text-gray-500 dark:text-slate-300 truncate">
                                    {{ ev.customer_name }}
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Pull past the edge to change week. Only shows once the lanes have run
         out of scroll, so panning across mechanics never nudges it. -->
    <WeekFlipIndicator :pull="pull" :armed="pullArmed" :progress="pullProgress" />
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import dayjs from '@/Utilities/dayjs'
import { initials, nlDayName, formatWbNumber } from '@/Utilities/Utilities'
import { UNAVAILABLE_PATTERN, formatDurationLabel } from '@/Utilities/plannerOverlaps'
import { daySegmentsFor, eventMinutesFor, eventsOnDay } from '@/Composables/usePlannerGaps'
import { usePullToFlip } from '@/Composables/usePullToFlip'
import WeekFlipIndicator from '@/Components/Planner/WeekFlipIndicator.vue'

const props = defineProps({
    plannableUsers: { type: Array, default: () => [] },
    weekDays: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
    dayStartHour: { type: Number, default: 7 },
    dayEndHour: { type: Number, default: 18 },
    allowOverrideUnavailability: { type: Boolean, default: false },
    canCreate: { type: Boolean, default: false },
    canGoForward: { type: Boolean, default: true },
})

const emit = defineEmits(['slot-tap', 'event-tap', 'flip-week'])

const HOUR_HEIGHT = 44
const LANE_WIDTH = 96
const HEADER_HEIGHT = 37
const DEFAULT_EVENT_COLOR = '#3b82f6'

/**
 * The window each day draws. It stretches past the bookable hours only for the
 * day that actually holds an out-of-hours appointment, because a lane that
 * silently omits work is worse than a taller lane — and one event running past
 * midnight shouldn't drag the rest of the week down to 00:00 with it.
 */
const rangeByDay = computed(() => {
    const byDay = new Map()
    for (const day of props.weekDays) {
        let startHour = props.dayStartHour
        let endHour = props.dayEndHour
        for (const ev of eventsOnDay(props.events, null, day.iso)) {
            const { startMin, endMin } = eventMinutesFor(ev, null, day.iso)
            if (endMin <= startMin) continue
            startHour = Math.min(startHour, Math.floor(startMin / 60))
            endHour = Math.max(endHour, Math.ceil(endMin / 60))
        }
        byDay.set(day.iso, { startHour, endHour })
    }
    return byDay
})

function rangeFor(dayIso) {
    return rangeByDay.value.get(dayIso) ?? { startHour: props.dayStartHour, endHour: props.dayEndHour }
}

function hoursFor(dayIso) {
    const { startHour, endHour } = rangeFor(dayIso)
    return Array.from({ length: endHour - startHour }, (_, i) => startHour + i)
}

function dayHeightFor(dayIso) {
    return hoursFor(dayIso).length * HOUR_HEIGHT
}

// The stretches of a drawn day that fall outside bookable hours.
function offHourFringesFor(dayIso) {
    const { startHour, endHour } = rangeFor(dayIso)
    const fringes = []
    if (startHour < props.dayStartHour) {
        fringes.push({ startMin: startHour * 60, endMin: props.dayStartHour * 60 })
    }
    if (endHour > props.dayEndHour) {
        fringes.push({ startMin: props.dayEndHour * 60, endMin: endHour * 60 })
    }
    return fringes
}

function positionFor({ startMin, endMin }, dayIso) {
    const { startHour, endHour } = rangeFor(dayIso)
    const totalMin = (endHour - startHour) * 60
    const clampedStart = Math.max(0, Math.min(totalMin, startMin - startHour * 60))
    const clampedEnd = Math.max(0, Math.min(totalMin, endMin - startHour * 60))
    return {
        top: (clampedStart / totalMin) * 100 + '%',
        height: Math.max(0, ((clampedEnd - clampedStart) / totalMin) * 100) + '%',
    }
}

// One pass per week rather than one per lane cell: the template asks for
// 11 mechanics x 7 days on every render.
const segmentsByLane = computed(() => {
    const byLane = new Map()
    for (const day of props.weekDays) {
        for (const user of props.plannableUsers) {
            byLane.set(`${day.iso}|${user.id}`, daySegmentsFor({
                events: props.events,
                plannableUsers: props.plannableUsers,
                userId: user.id,
                dayIso: day.iso,
                dayStartHour: props.dayStartHour,
                dayEndHour: props.dayEndHour,
            }))
        }
    }
    return byLane
})

const eventsByLane = computed(() => {
    const byLane = new Map()
    for (const day of props.weekDays) {
        for (const user of props.plannableUsers) {
            byLane.set(`${day.iso}|${user.id}`, eventsOnDay(props.events, user.id, day.iso))
        }
    }
    return byLane
})

function segmentsFor(userId, dayIso) {
    return segmentsByLane.value.get(`${dayIso}|${userId}`) ?? []
}

function eventsFor(userId, dayIso) {
    return eventsByLane.value.get(`${dayIso}|${userId}`) ?? []
}

function segmentTappable(segment) {
    if (!props.canCreate) return false
    return segment.kind === 'free' || props.allowOverrideUnavailability
}

// Panning sideways across the mechanics is the common gesture here, so the week
// only changes on a deliberate pull past the edge of the lanes.
const scrollEl = ref(null)
const { pull, pullArmed, pullProgress, onTouchStart, onTouchMove, onTouchEnd } = usePullToFlip(scrollEl, {
    canGoForward: () => props.canGoForward,
    onFlip: (direction) => emit('flip-week', direction),
})
</script>
