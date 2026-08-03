<template>
    <div class="relative inline-flex flex-none items-center justify-center"
        :style="{ width: size + 'px', height: size + 'px' }">
        <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" class="-rotate-90">
            <circle :cx="center" :cy="center" :r="radius" fill="none" stroke="currentColor"
                :stroke-width="stroke" class="text-gray-200 dark:text-slate-700" />
            <circle v-for="segment in renderedSegments" :key="segment.index" :cx="center" :cy="center"
                :r="radius" fill="none" :stroke="segment.color" :stroke-width="stroke" :stroke-linecap="segment.cap"
                pathLength="100" :stroke-dasharray="`${segment.length} 100`" :stroke-dashoffset="-segment.offset"
                :class="['transition-all duration-700 ease-out', selectable ? 'cursor-pointer' : '']"
                :style="{ opacity: active === null || active === segment.index ? 1 : 0.45 }"
                @mouseenter="selectable && (hovered = segment.index)" @mouseleave="hovered = null"
                @click="selectable && emit('select', segment.index)">
                <title v-if="segment.title">{{ segment.title }}</title>
            </circle>
        </svg>
        <!-- pointer-events-none: dit laagje ligt over de hele ring en zou anders elke
             klik en elke hover op een schijf opvangen. -->
        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
            <span class="font-bold leading-none text-gray-900 dark:text-slate-100"
                :style="{ fontSize: valueFontSize }">{{ displayValue }}</span>
            <span v-if="label" class="mt-1 text-xs text-gray-400 dark:text-slate-500">{{ label }}</span>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'

/**
 * Aanwijsbaar maken doet de aanroeper: alleen wie iets met een klik doet, zet
 * `selectable` aan. Zonder dat blijft de ring precies wat hij was — een plaatje.
 */
const emit = defineEmits(['select']);

const props = defineProps({
    segments: { type: Array, default: () => [] },
    selectable: { type: Boolean, default: false },
    /** Laat een legenda buiten de ring dezelfde schijf oplichten als hijzelf. */
    highlight: { type: Number, default: null },
    total: { type: Number, default: null },
    label: { type: String, default: '' },
    size: { type: Number, default: 120 },
    stroke: { type: Number, default: 12 },
})

const hovered = ref(null)
const active = computed(() => props.highlight ?? hovered.value)

const center = computed(() => props.size / 2)
const radius = computed(() => (props.size - props.stroke) / 2)
const valueFontSize = computed(() => Math.round(props.size * 0.28) + 'px')

const sum = computed(() => props.segments.reduce((total, segment) => total + (segment.value || 0), 0))
const displayValue = computed(() => props.total ?? sum.value)

const gapSize = 3

/**
 * Lege schijven worden niet getekend, maar houden wel hun plek in de nummering:
 * `index` blijft de plek in `segments` zoals de aanroeper hem meegaf. Zou hier de
 * plek ná het filteren gebruikt worden, dan wees een klik op de derde zichtbare
 * schijf naar het derde item van de aanroeper — en dat is een ander zodra er
 * eentje op nul staat.
 */
const renderedSegments = computed(() => {
    if (sum.value <= 0) return []
    const active = props.segments
        .map((segment, index) => ({ ...segment, index }))
        .filter(segment => segment.value > 0)
    const multiple = active.length > 1
    let offset = 0
    return active.map(segment => {
        const raw = (segment.value / sum.value) * 100
        const length = multiple ? Math.max(raw - gapSize, 0.5) : raw
        const rendered = {
            index: segment.index,
            color: segment.color,
            title: segment.title,
            length,
            offset,
            cap: multiple ? 'butt' : 'round',
        }
        offset = offset + raw
        return rendered
    })
})
</script>
