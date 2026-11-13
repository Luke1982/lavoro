<template>
    <svg ref="svg" class="pointer-events-none absolute inset-0 h-full w-full overflow-visible">
        <g v-for="line in drawn" :key="line.key">
            <path :d="line.d" fill="none" :stroke="line.color" :stroke-width="line.width"
                :stroke-dasharray="line.required ? null : '5 4'" :opacity="line.opacity" />
            <path :d="line.d" fill="none" stroke="transparent" stroke-width="14" class="cursor-pointer" style="pointer-events: stroke"
                @click.stop="event => $emit('line-click', line.link, event)" />
        </g>
        <path v-if="dragPath" :d="dragPath" fill="none" stroke="#2563eb" stroke-width="2" stroke-dasharray="3 3" />
    </svg>
</template>

<script setup>
import { computed, inject, ref } from 'vue'
import { useCardAnchors } from '@/Composables/useCardAnchors.js'

/**
 * The lines between linked cards. Solid is required, dashed is optional. With a
 * card selected, its own lines turn blue and the rest fade. Each line has a wide
 * invisible twin on top, so it can be clicked without aiming at two pixels.
 */
const props = defineProps({
    links: { type: Array, required: true },
    highlighted: { type: Set, default: null },
})

defineEmits(['line-click'])

/**
 * Measured and redrawn here rather than in the page, so scrolling a column or
 * dragging a card only redraws the lines, not every card on the board.
 */
const svg = ref(null)
const board = computed(() => svg.value?.parentElement ?? null)
const anchors = useCardAnchors(board)
const drag = inject('linkDrag')

function curve(from, to) {
    const dx = Math.max(40, (to.x - from.x) / 2)

    return `M ${from.x} ${from.y} C ${from.x + dx} ${from.y}, ${to.x - dx} ${to.y}, ${to.x} ${to.y}`
}

const drawn = computed(() => props.links.flatMap(link => {
    const from = anchors.value.get(link.from)
    const to = anchors.value.get(link.to)

    if (!from || !to) return []

    const lit = props.highlighted?.has(link.from) && props.highlighted?.has(link.to)

    return [{
        key: link.key,
        link,
        required: link.required,
        d: curve(from.right, to.left),
        color: lit ? '#2563eb' : '#94a3b8',
        width: lit ? 2.5 : 1.75,
        opacity: props.highlighted && !lit ? 0.2 : 1,
    }]
}))

const dragPath = computed(() => {
    const from = drag.value && anchors.value.get(drag.value.from)

    return from ? curve(from.right, { x: drag.value.x, y: drag.value.y }) : null
})
</script>
