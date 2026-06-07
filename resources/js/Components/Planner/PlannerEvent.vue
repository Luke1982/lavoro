<template>
    <div data-planner-event
        class="absolute rounded-md shadow-sm cursor-grab select-none border-l-4 overflow-hidden text-gray-900"
        :class="[
            isBeingDragged ? 'opacity-30' : '',
            isLocked ? 'ring-1 ring-offset-1 ring-blue-300/60' : '',
        ]"
        :style="style"
        @pointerdown.stop="onPointerDown"
        @click.stop="$emit('click')"
        @contextmenu.prevent.stop="$emit('contextmenu', $event)">
        <VDropdown :triggers="popoverTriggers" :disabled="!isShort || isBeingDragged"
            :delay="{ show: 200, hide: 80 }" placement="top">
            <div class="px-3 py-2 flex flex-col h-full justify-between overflow-hidden"
                :class="isCompact ? 'justify-start py-1' : ''">
                <div class="text-xs font-semibold leading-tight truncate flex items-center gap-1">
                    <ExclamationTriangleIcon v-if="event.is_preliminary" class="size-3 shrink-0 text-amber-500" />
                    #{{ event.id }} {{ event.name || event.title }}
                </div>
                <div v-if="event.customer_name && !isCompact" class="text-[11px] text-gray-600 truncate">
                    {{ event.customer_name }}
                </div>
                <div v-if="!isCompact" class="text-[11px] text-gray-600 flex items-center gap-1">
                    <ClockIcon class="size-3 shrink-0" />
                    <span class="truncate">{{ formatTime(event.start) }} – {{ formatTime(event.end) }}</span>
                    <span v-if="isLocked" class="ml-1" v-tooltip="'Vergrendeld – meerdere monteurs delen dit'">🔒</span>
                </div>
                <button v-if="!isCompact && event.eventable_id"
                    class="mt-1 inline-flex items-center gap-1 text-[10px] text-gray-600 bg-white/80 border border-gray-200 rounded px-1.5 py-0.5 hover:border-gray-300 transition leading-none"
                    @click.stop="router.visit(`/serviceorders/${event.eventable_id}`)">
                    <BuildingOfficeIcon class="size-3 shrink-0" />
                    <span>WB-{{ String(event.eventable_id).padStart(4, '0') }}</span>
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
                        {{ formatTime(event.start) }} – {{ formatTime(event.end) }}
                    </div>
                    <div v-if="event.description" class="text-xs mt-2 whitespace-pre-line">
                        {{ event.description }}
                    </div>
                </div>
            </template>
        </VDropdown>
        <div class="absolute top-0 bottom-0 left-0 w-1.5 cursor-ew-resize"
            @pointerdown.stop="onResize($event, 'start')"></div>
        <div class="absolute top-0 bottom-0 right-0 w-1.5 cursor-ew-resize"
            @pointerdown.stop="onResize($event, 'end')"></div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { ClockIcon, ExclamationTriangleIcon, BuildingOfficeIcon, ArrowTopRightOnSquareIcon } from '@heroicons/vue/24/outline'
import { router } from '@inertiajs/vue3'
import { nlTime } from '@/Utilities/Utilities'

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
})

const emit = defineEmits(['click', 'contextmenu', 'pointerdown-on-event', 'pointerdown-on-resize'])

const totalMin = computed(() => (props.dayEndHour - props.dayStartHour) * 60)
const popoverTriggers = ['hover', 'focus']

function minutesFromDayStart(date) {
    return date.getHours() * 60 + date.getMinutes() - props.dayStartHour * 60
}

const durationMinutes = computed(() => (props.event.end - props.event.start) / 60000)
const isShort = computed(() => durationMinutes.value < 60)
// Lane is collapsed/compact — show only the title so nothing overflows the short card.
const isCompact = computed(() => props.rowHeight < 70)

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
