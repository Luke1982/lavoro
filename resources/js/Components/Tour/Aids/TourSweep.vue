<template>
    <div v-if="rect" class="pointer-events-none fixed left-0 top-0 z-[10002] overflow-hidden rounded-xl" :style="frameStyle"
        aria-hidden="true">
        <div class="tour-sweep absolute inset-y-0 w-24" :class="{ 'tour-sweep-still': reduced }" />
        <div class="absolute left-1/2 top-3 flex -translate-x-1/2 items-center gap-2 rounded-full bg-lavoro-dark/80 px-3 py-1 text-xs font-medium text-white shadow-lg">
            <span>tijd</span>
            <svg width="44" height="10" viewBox="0 0 44 10" class="tour-sweep-arrow">
                <path d="M0 5H40M35 1L40 5L35 9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            </svg>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useTrackedSpot } from '@/Tours/useTrackedSpot'
import { prefersReducedMotion } from '@/Tours/engine'

/** Time running left to right across the planner: a band of light that keeps passing. */
const props = defineProps({
    aid: { type: Object, required: true },
})

const { rect } = useTrackedSpot(props.aid.to)
const reduced = prefersReducedMotion()

const frameStyle = computed(() => ({
    transform: 'translate3d(' + rect.value.left + 'px, ' + rect.value.top + 'px, 0)',
    width: rect.value.width + 'px',
    height: rect.value.height + 'px',
}))
</script>

<style>
.tour-sweep {
    left: -6rem;
    background: linear-gradient(90deg, transparent, rgb(198 255 0 / 0.08) 40%, rgb(198 255 0 / 0.35) 85%, rgb(198 255 0 / 0.9) 100%);
    animation: tour-sweep 3.2s cubic-bezier(.45, 0, .55, 1) infinite;
}

.tour-sweep-still {
    animation: none;
    left: 50%;
}

@keyframes tour-sweep {
    from { left: -6rem; }
    to { left: 100%; }
}
</style>
