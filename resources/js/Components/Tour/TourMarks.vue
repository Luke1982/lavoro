<template>
    <div v-for="box in boxes" :key="box.key" class="tour-mark pointer-events-none fixed z-[10002]"
        :style="{ left: box.left + 'px', top: box.top + 'px', width: box.width + 'px', height: box.height + 'px' }" />
</template>

<script setup>
import { onBeforeUnmount, onMounted, shallowRef } from 'vue'
import { tourState, findSpotAll, visibleRect } from '@/Tours/engine'

const PADDING = 4
const LIMIT = 24

/**
 * The smaller lights of a step: a spot starts to pulse at the word in the
 * spoken line that names it, such as every row's arrow when the voice says
 * "pijltje", and goes out when the voice moves on to the next one. Measured
 * every frame, so they stay on their spot while the page scrolls or a row folds
 * away underneath.
 */
const boxes = shallowRef([])
const found = new Map()
let raf = null

function elementsOf(mark) {
    const known = found.get(mark.key)
    if (known?.length && known.every((element) => element.isConnected)) return known
    const fresh = findSpotAll(mark.spot).slice(0, LIMIT)
    found.set(mark.key, fresh)

    return fresh
}

function current() {
    if (tourState.phase !== 'step') return []
    const progress = tourState.caption.progress
    const reached = tourState.marks.filter((mark) => mark.at <= progress)
    if (!reached.length) return []
    const latest = Math.max(...reached.map((mark) => mark.at))

    return reached.filter((mark) => mark.at === latest)
}

function measure() {
    raf = requestAnimationFrame(measure)
    const next = []
    for (const mark of current()) {
        elementsOf(mark).forEach((element, index) => {
            const rect = visibleRect(element)
            if (rect.width <= 0 || rect.height <= 0) return
            next.push({
                key: mark.key + ':' + index,
                left: Math.round(rect.left - PADDING),
                top: Math.round(rect.top - PADDING),
                width: Math.round(rect.width + PADDING * 2),
                height: Math.round(rect.height + PADDING * 2),
            })
        })
    }
    const previous = boxes.value
    const same = previous.length === next.length && next.every((box, index) => {
        const old = previous[index]

        return old.key === box.key && old.left === box.left && old.top === box.top
            && old.width === box.width && old.height === box.height
    })
    if (!same) boxes.value = next
}

onMounted(() => { raf = requestAnimationFrame(measure) })
onBeforeUnmount(() => cancelAnimationFrame(raf))
</script>

<style>
.tour-mark {
    border-radius: 8px;
    background: rgb(198 255 0 / 0.18);
    box-shadow: 0 0 0 2px var(--color-lavoro-blue), 0 0 18px 4px rgb(198 255 0 / 0.55);
    animation: tour-mark-in 0.35s ease-out, tour-mark-pulse 1.2s ease-in-out 0.35s infinite;
}

@keyframes tour-mark-in {
    from {
        opacity: 0;
        scale: 1.8;
    }
}

@keyframes tour-mark-pulse {
    50% {
        box-shadow: 0 0 0 3px var(--color-lavoro-blue), 0 0 26px 8px rgb(198 255 0 / 0.75);
    }
}

.tour-reduced .tour-mark {
    animation: none;
}

@media (prefers-reduced-motion: reduce) {
    .tour-mark {
        animation: none;
    }
}
</style>
