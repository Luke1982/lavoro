<template>
    <div data-planner-event
        class="absolute rounded-md shadow-sm cursor-grab select-none border overflow-hidden"
        :class="[
            isBeingDragged ? 'opacity-30' : '',
            isLocked ? 'ring-1 ring-offset-1 ring-blue-300/60' : '',
        ]"
        :style="style"
        @pointerdown.stop="onPointerDown"
        @click.stop="$emit('click')">
        <div class="px-2 py-1 flex flex-col h-full justify-between">
            <div class="text-[11px] font-semibold leading-tight truncate">
                #{{ event.id }} {{ event.name || event.title }}
            </div>
            <div class="text-[10px] opacity-80 truncate">
                {{ event.customer_name || (event.executing_users[0]?.name) }}
            </div>
            <div class="text-[10px] opacity-90 flex items-center gap-1">
                <ClockIcon class="size-3" />
                {{ formatTime(event.start) }} – {{ formatTime(event.end) }}
                <span v-if="isLocked" class="ml-1" v-tooltip="'Vergrendeld – meerdere monteurs delen dit'">🔒</span>
            </div>
        </div>
        <div class="absolute top-0 bottom-0 left-0 w-1.5 cursor-ew-resize"
            @pointerdown.stop="onResize($event, 'start')"></div>
        <div class="absolute top-0 bottom-0 right-0 w-1.5 cursor-ew-resize"
            @pointerdown.stop="onResize($event, 'end')"></div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { ClockIcon } from '@heroicons/vue/24/outline'
import { nlTime } from '@/Utilities/Utilities'

const props = defineProps({
    event: { type: Object, required: true },
    userId: { type: Number, required: true },
    day: { type: Object, required: true },
    slotMinutes: { type: Number, default: 30 },
    dayStartHour: { type: Number, default: 7 },
    dayEndHour: { type: Number, default: 18 },
    rowHeight: { type: Number, default: 96 },
    isLocked: { type: Boolean, default: false },
    isBeingDragged: { type: Boolean, default: false },
})

const emit = defineEmits(['click', 'pointerdown-on-event', 'pointerdown-on-resize'])

const totalMin = computed(() => (props.dayEndHour - props.dayStartHour) * 60)

function minutesFromDayStart(date) {
    return date.getHours() * 60 + date.getMinutes() - props.dayStartHour * 60
}

const style = computed(() => {
    const startMin = Math.max(0, minutesFromDayStart(props.event.start))
    const endMin = Math.min(totalMin.value, minutesFromDayStart(props.event.end))
    const leftPct = (startMin / totalMin.value) * 100
    const widthPct = Math.max(2, ((endMin - startMin) / totalMin.value) * 100)
    const color = props.event.color || '#3b82f6'
    return {
        left: leftPct + '%',
        width: widthPct + '%',
        top: '4px',
        bottom: '4px',
        backgroundColor: color + '22',
        borderColor: color,
        color: color,
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
