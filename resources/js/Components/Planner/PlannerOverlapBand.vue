<template>
    <div data-overlap-band :data-stack-count="band.stackCount" class="@container absolute rounded-sm pointer-events-none"
        :style="style">
        <div v-if="!band.coversCards" class="absolute inset-0 pointer-events-auto" v-tooltip="tooltip"></div>
        <div class="absolute inset-0 items-center justify-center"
            :class="isStack ? 'flex' : 'hidden @min-[70px]:flex'">
            <span
                class="inline-flex items-center gap-1 rounded-full bg-red-600 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-white shadow-sm"
                :class="isStack ? 'pointer-events-auto' : ''" v-tooltip="isStack ? tooltip : null">
                <TriangleAlert v-if="isStack" class="size-3 shrink-0" />
                <CircleAlert v-else class="size-3 shrink-0" />
                <span :class="isStack ? 'hidden @min-[70px]:inline' : ''">{{ label }}</span>
            </span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { TriangleAlert, CircleAlert } from '@lucide/vue'
import { formatDurationLabel } from '@/Utilities/plannerOverlaps'

const props = defineProps({
    band: { type: Object, required: true },
    dayStartHour: { type: Number, default: 7 },
    dayEndHour: { type: Number, default: 18 },
    eventPaddingY: { type: Number, default: 14 },
})

const totalMin = computed(() => (props.dayEndHour - props.dayStartHour) * 60)
const isStack = computed(() => props.band.stackCount > 1)

// Always the band's own span. The stack count describes the cards underneath it,
// which can cover a shorter stretch, so the two never share a label.
const label = computed(() => formatDurationLabel(props.band.durationMin))

const tooltip = computed(() => isStack.value
    ? `${props.band.stackCount} afspraken op exact dezelfde tijd`
    : `${label.value} overlap`
)

const style = computed(() => {
    const startMin = Math.max(0, props.band.startMin)
    const endMin = Math.min(totalMin.value, props.band.endMin)
    return {
        left: (startMin / totalMin.value) * 100 + '%',
        width: ((endMin - startMin) / totalMin.value) * 100 + '%',
        top: props.eventPaddingY + 'px',
        bottom: props.eventPaddingY + 'px',
        backgroundImage: `repeating-linear-gradient(-45deg, transparent, transparent 4px, color-mix(in srgb, ${props.band.color} 45%, transparent) 4px, color-mix(in srgb, ${props.band.color} 45%, transparent) 9px)`,
        zIndex: 7,
    }
})
</script>
