<template>
    <div data-planner-event :data-event-id="event.id"
        class="absolute rounded-md shadow-sm cursor-grab select-none border-l-4 text-gray-900"
        :class="[
            isBeingDragged ? 'opacity-30' : '',
            isLocked ? 'ring-1 ring-offset-1 ring-blue-300/60' : '',
            isHighlighted ? 'ring-2 ring-offset-2 ring-lavoro-blue animate-pulse' : '',
        ]" :style="style" @pointerdown.stop="onPointerDown" @click.stop="$emit('click')"
        @contextmenu.prevent.stop="$emit('contextmenu', $event)">
        <VDropdown :triggers="popoverTriggers" :disabled="!isShort || isBeingDragged" :delay="{ show: 200, hide: 80 }"
            placement="top">
            <!-- Content sticks to the left visible grid edge (past the frozen sidebar) so it stays readable while scrolling horizontally. -->
            <div class="px-3 py-2 flex flex-col h-full justify-between overflow-hidden sticky left-64"
                :class="isCompact ? 'justify-start py-1' : ''">
                <div class="text-xs font-semibold leading-tight truncate flex items-center gap-1">
                    <ExclamationTriangleIcon v-if="event.is_preliminary" class="size-3 shrink-0 text-amber-500" />
                    <ArrowsRightLeftIcon v-if="isSourceWithDivergingCompanions" class="size-3 shrink-0 text-lavoro-blue"
                        v-tooltip="'Uitvoerende met afwijkende tijden'" />
                    #{{ event.id }} {{ event.name || event.title }}
                </div>
                <div v-if="event.customer_name && !isCompact" class="text-[11px] text-gray-600 truncate">
                    {{ event.customer_name }}
                </div>
                <div v-if="!isCompact" class="text-[11px] text-gray-600 flex items-center gap-1">
                    <ClockIcon class="size-3 shrink-0" />
                    <span class="truncate">{{ effectiveStartLabel }} – {{ effectiveEndLabel }}</span>
                    <template v-if="userBreaktime">
                        <ClockFading class="size-3 shrink-0 ml-0.5 text-gray-400" />
                        <span class="text-gray-400">{{ userBreaktime }}m</span>
                    </template>
                    <span v-if="isLocked" class="ml-1" v-tooltip="'Vergrendeld – meerdere monteurs delen dit'">🔒</span>
                </div>
                <div v-if="!isCompact && event.location" class="text-[11px] text-gray-500 flex items-center gap-1">
                    <MapPinIcon class="size-3 shrink-0" />
                    <span class="truncate">{{ event.location }}</span>
                </div>
                <div v-if="!isCompact && !event.eventable_id && event.description"
                    class="text-[11px] text-gray-600 mt-0.5 line-clamp-2 whitespace-pre-line">
                    {{ event.description }}
                </div>
                <button v-if="!isCompact && event.eventable_id"
                    class="mt-1 inline-flex items-center gap-1 text-[10px] text-gray-600 bg-white/80 border border-gray-200 rounded px-1.5 py-1.5 hover:border-gray-300 transition leading-none"
                    @click.stop="router.visit(`/serviceorders/${event.eventable_id}`)">
                    <BuildingOfficeIcon class="size-3 shrink-0" />
                    <span class="truncate">WB-{{ String(event.eventable_id).padStart(4, '0') }}</span>
                    <template v-if="event.project_name">
                        <ClipboardDocumentListIcon class="size-3 shrink-0 text-lavoro-blue"
                            v-tooltip="event.project_name" />
                    </template>
                    <TriangleAlert v-if="event.is_incomplete" class="size-3 shrink-0 text-amber-500"
                        v-tooltip="'Werkbon gedeeltelijk afgerond'" />
                    <CircleCheck v-else-if="event.is_closed" class="size-3 shrink-0 text-green-600"
                        v-tooltip="'Werkbon afgerond'" />
                    <Banknote v-if="event.is_invoiced" class="size-3 shrink-0 text-emerald-600"
                        v-tooltip="'Werkbon gefactureerd'" />
                    <ArrowTopRightOnSquareIcon class="size-3 shrink-0" />
                </button>
            </div>
            <template #popper>
                <div class="p-3 text-gray-900 dark:text-slate-100 max-w-xs min-w-[14rem]">
                    <div class="text-sm font-semibold leading-tight">
                        #{{ event.id }} {{ event.name || event.title }}
                    </div>
                    <div v-if="event.customer_name" class="text-xs mt-1 text-gray-700 dark:text-slate-200">
                        <span class="text-gray-500 dark:text-slate-400">Klant:</span> {{ event.customer_name }}
                    </div>
                    <div v-if="event.eventable_id" class="text-xs mt-0.5 text-gray-700 dark:text-slate-200">
                        <span class="text-gray-500 dark:text-slate-400">Werkbon:</span> #{{ event.eventable_id }}
                    </div>
                    <div class="text-xs mt-1 flex items-center gap-1 text-gray-600 dark:text-slate-300">
                        <ClockIcon class="size-3" />
                        {{ effectiveStartLabel }} – {{ effectiveEndLabel }}
                    </div>
                    <div v-if="event.location"
                        class="text-xs mt-1 flex items-center gap-1 text-gray-600 dark:text-slate-300">
                        <MapPinIcon class="size-3 shrink-0" />
                        {{ event.location }}
                    </div>
                    <div v-if="event.description" class="text-xs mt-2 whitespace-pre-line">
                        {{ event.description }}
                    </div>
                </div>
            </template>
        </VDropdown>
        <div class="absolute top-1 right-2 flex items-center gap-1 pointer-events-none">
            <button v-if="hasPermission('event.provide_feedback') && !event.eventable_id" class="pointer-events-auto relative"
                @click.stop="$emit('open-feedback', event)" v-tooltip="'Terugkoppeling'">
                <MessageCircleReply class="size-3 text-gray-500 hover:text-lavoro-blue" />
                <span v-if="feedbackCount > 0"
                    class="absolute -top-1.5 -right-1.5 min-w-[13px] h-[13px] px-0.5 rounded-full bg-lavoro-blue text-white text-[8px] leading-[13px] text-center font-semibold">
                    {{ feedbackCount }}
                </span>
            </button>
            <EventExecutionControls :event="event" class="pointer-events-auto"
                @changed="$emit('changed')" />
            <span v-for="role in currentUserRoles" :key="role.id"
                class="inline-flex items-center rounded px-1 py-px text-[9px] font-semibold leading-none text-white"
                :style="{ backgroundColor: role.color }" v-tooltip="role.name">
                {{ roleInitials(role.name) }}
            </span>
            <div v-if="event.from_google" v-tooltip="'Afkomstig uit Google Calendar'">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="size-3">
                    <path
                        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                        fill="#4285F4" />
                    <path
                        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                        fill="#34A853" />
                    <path
                        d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                        fill="#FBBC05" />
                    <path
                        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                        fill="#EA4335" />
                </svg>
            </div>

        </div>
        <div class="absolute top-0 bottom-0 left-0 w-1.5 cursor-ew-resize"
            @pointerdown.stop="onResize($event, 'start')"></div>
        <div class="absolute top-0 bottom-0 right-0 w-1.5 cursor-ew-resize" @pointerdown.stop="onResize($event, 'end')">
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { ClockIcon, ExclamationTriangleIcon, BuildingOfficeIcon, ArrowTopRightOnSquareIcon, MapPinIcon, ClipboardDocumentListIcon, ArrowsRightLeftIcon } from '@heroicons/vue/24/outline'
import EventExecutionControls from '@/Components/Planner/EventExecutionControls.vue'
import { ClockFading, TriangleAlert, CircleCheck, Banknote, MessageCircleReply } from '@lucide/vue'
import { router } from '@inertiajs/vue3'
import { nlTime, hasPermission } from '@/Utilities/Utilities'

const COMPLETED_PATTERN = 'repeating-linear-gradient(-45deg, transparent, transparent 6px, rgba(107,114,128,0.07) 6px, rgba(107,114,128,0.07) 12px)'

const props = defineProps({
    event: { type: Object, required: true },
    userId: { type: Number, required: true },
    day: { type: Object, required: true },
    slotMinutes: { type: Number, default: 30 },
    dayStartHour: { type: Number, default: 7 },
    dayEndHour: { type: Number, default: 18 },
    rowHeight: { type: Number, default: 120 },
    eventPaddingY: { type: Number, default: 14 },
    isLocked: { type: Boolean, default: false },
    isBeingDragged: { type: Boolean, default: false },
    isHighlighted: { type: Boolean, default: false },
    userRoles: { type: Array, default: () => [] },
})

const emit = defineEmits(['click', 'contextmenu', 'pointerdown-on-event', 'pointerdown-on-resize', 'changed', 'open-feedback'])

const totalMin = computed(() => (props.dayEndHour - props.dayStartHour) * 60)
const feedbackCount = computed(() => (props.event.remarks_count || 0) + (props.event.images_count || 0))
const popoverTriggers = ['hover', 'focus']

const currentUserRoles = computed(() => {
    const user = props.event.executing_users?.find(u => u.id === props.userId)
    if (!user?.user_role_ids?.length) return []
    return user.user_role_ids
        .map(id => props.userRoles.find(r => r.id === id))
        .filter(Boolean)
})

function roleInitials(name) {
    return name.split(/\s+/).map(w => w[0]).join('').slice(0, 3).toUpperCase()
}

const userStatus = computed(() =>
    props.event.executing_users?.find(u => u.id === props.userId)?.completion_status ?? 'Gepland'
)

const isClosedForUser = computed(() =>
    userStatus.value === 'Afgerond' || userStatus.value === 'Geannuleerd'
)

function minutesFromDayStart(date) {
    return date.getHours() * 60 + date.getMinutes() - props.dayStartHour * 60
}

const durationMinutes = computed(() => (props.event.end - props.event.start) / 60000)
const isShort = computed(() => durationMinutes.value < 60)
const userBreaktime = computed(() =>
    props.event.executing_users?.find(u => u.id === props.userId)?.breaktime ?? 0
)

const divergingUser = computed(() =>
    props.event.executing_users?.find(u => u.id === props.userId && u.has_diverging_times) ?? null
)

const isSourceWithDivergingCompanions = computed(() =>
    !divergingUser.value && (props.event.executing_users?.some(u => u.has_diverging_times) ?? false)
)

const effectiveStartMin = computed(() => {
    if (divergingUser.value?.diverging_start) {
        const [h, m] = divergingUser.value.diverging_start.slice(0, 5).split(':').map(Number)
        return h * 60 + m - props.dayStartHour * 60
    }
    return minutesFromDayStart(props.event.start)
})

const effectiveEndMin = computed(() => {
    if (divergingUser.value?.diverging_end) {
        const [h, m] = divergingUser.value.diverging_end.slice(0, 5).split(':').map(Number)
        return h * 60 + m - props.dayStartHour * 60
    }
    return minutesFromDayStart(props.event.end)
})

const effectiveStartLabel = computed(() =>
    divergingUser.value?.diverging_start
        ? divergingUser.value.diverging_start.slice(0, 5)
        : formatTime(props.event.start)
)

const effectiveEndLabel = computed(() =>
    divergingUser.value?.diverging_end
        ? divergingUser.value.diverging_end.slice(0, 5)
        : formatTime(props.event.end)
)

// Lane is collapsed/compact — show only the title so nothing overflows the short card.
const isCompact = computed(() => props.rowHeight < 70)

const style = computed(() => {
    const startMin = Math.max(0, effectiveStartMin.value)
    const endMin = Math.min(totalMin.value, effectiveEndMin.value)
    const leftPct = (startMin / totalMin.value) * 100
    const widthPct = Math.max(2, ((endMin - startMin) / totalMin.value) * 100)
    const color = isClosedForUser.value ? '#6b7280' : (props.event.color || '#3b82f6')
    const bgStrength = props.event.is_preliminary ? '8%' : '18%'
    return {
        left: leftPct + '%',
        width: widthPct + '%',
        top: props.eventPaddingY + 'px',
        bottom: props.eventPaddingY + 'px',
        backgroundColor: `color-mix(in srgb, ${color} ${bgStrength}, white)`,
        ...(isClosedForUser.value ? { backgroundImage: COMPLETED_PATTERN } : {}),
        borderColor: color,
        borderLeftStyle: props.event.is_preliminary ? 'dashed' : 'solid',
        transition: 'top 200ms ease-in-out, bottom 200ms ease-in-out',
        zIndex: 6,
    }
})

function formatTime(date) {
    return nlTime(date)
}

function onPointerDown(e) {
    emit('pointerdown-on-event', e)
}

function onResize(e, edge) {
    e.edge = edge
    emit('pointerdown-on-resize', e)
}
</script>
