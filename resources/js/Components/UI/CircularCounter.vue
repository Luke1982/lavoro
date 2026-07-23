<template>
    <div class="relative inline-flex flex-none items-center justify-center"
        :style="{ width: size + 'px', height: size + 'px' }">
        <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" class="-rotate-90">
            <circle :cx="center" :cy="center" :r="radius" fill="none" stroke="currentColor"
                :stroke-width="stroke" class="text-gray-200 dark:text-slate-700" />
            <circle v-for="(segment, index) in renderedSegments" :key="index" :cx="center" :cy="center"
                :r="radius" fill="none" :stroke="segment.color" :stroke-width="stroke" :stroke-linecap="segment.cap"
                pathLength="100" :stroke-dasharray="`${segment.length} 100`" :stroke-dashoffset="-segment.offset"
                class="transition-all duration-700 ease-out" />
        </svg>
        <div class="absolute inset-0 flex flex-col items-center justify-center">
            <span class="font-bold leading-none text-gray-900 dark:text-slate-100"
                :style="{ fontSize: valueFontSize }">{{ displayValue }}</span>
            <span v-if="label" class="mt-1 text-xs text-gray-400 dark:text-slate-500">{{ label }}</span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    segments: { type: Array, default: () => [] },
    total: { type: Number, default: null },
    label: { type: String, default: '' },
    size: { type: Number, default: 120 },
    stroke: { type: Number, default: 12 },
})

const center = computed(() => props.size / 2)
const radius = computed(() => (props.size - props.stroke) / 2)
const valueFontSize = computed(() => Math.round(props.size * 0.28) + 'px')

const sum = computed(() => props.segments.reduce((total, segment) => total + (segment.value || 0), 0))
const displayValue = computed(() => props.total ?? sum.value)

const gapSize = 3

const renderedSegments = computed(() => {
    if (sum.value <= 0) return []
    const active = props.segments.filter(segment => segment.value > 0)
    const multiple = active.length > 1
    let offset = 0
    return active.map(segment => {
        const raw = (segment.value / sum.value) * 100
        const length = multiple ? Math.max(raw - gapSize, 0.5) : raw
        const rendered = { color: segment.color, length, offset, cap: multiple ? 'butt' : 'round' }
        offset = offset + raw
        return rendered
    })
})
</script>
