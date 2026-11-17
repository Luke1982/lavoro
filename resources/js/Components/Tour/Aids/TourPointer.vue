<template>
    <div v-if="rect" class="pointer-events-none fixed left-0 top-0 z-[10002]" :style="anchorStyle">
        <div v-if="aid.gesture === 'drag'" ref="trail" class="tour-drag-trail absolute rounded-md" />
        <div ref="pointer" class="absolute left-0 top-0">
            <span ref="ripple" class="tour-ripple absolute -left-5 -top-5 size-10 rounded-full" />
            <span v-if="touch" class="tour-finger absolute -left-4 -top-4 block size-8 rounded-full" />
            <svg v-else class="tour-cursor relative block" width="26" height="30" viewBox="0 0 26 30" aria-hidden="true">
                <path d="M2 2 L2 24 L8 18.5 L12.5 28 L16.5 26.2 L12 16.8 L20 16.8 Z" fill="white" stroke="#081020"
                    stroke-width="1.8" stroke-linejoin="round" />
            </svg>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useTrackedSpot } from '@/Tours/useTrackedSpot'
import { prefersReducedMotion } from '@/Tours/engine'

/**
 * A hand on the screen: it comes in, clicks (with a ripple), drags a stretch of
 * the spot, or swipes across it — on a phone as a fingertip, with a mouse as an
 * arrow. It only acts out the gesture; nothing underneath is touched.
 */
const props = defineProps({
    aid: { type: Object, required: true },
})

const { rect } = useTrackedSpot(props.aid.to)
const pointer = ref(null)
const ripple = ref(null)
const trail = ref(null)
const touch = typeof window !== 'undefined' && window.matchMedia('(pointer: coarse)').matches
const animations = []
let started = false

const anchorStyle = computed(() => {
    if (!rect.value) return {}
    const box = rect.value
    const vertical = props.aid.axis === 'y'
    const x = props.aid.gesture === 'drag' && !vertical ? box.left + Math.min(box.width * 0.25, 60) : box.left + box.width / 2
    const y = props.aid.gesture === 'drag' && vertical ? box.top + Math.min(box.height * 0.25, 30) : box.top + box.height / 2

    return { transform: 'translate3d(' + x + 'px, ' + y + 'px, 0)' }
})

function play() {
    if (started || !pointer.value || !rect.value) return
    started = true
    const reduced = prefersReducedMotion()
    const box = rect.value
    const gesture = props.aid.gesture ?? 'click'

    if (reduced) {
        animations.push(pointer.value.animate([{ opacity: 1 }], { duration: 1, fill: 'forwards' }))

        return
    }

    if (gesture === 'click') {
        animations.push(pointer.value.animate([
            { transform: 'translate(70px, 80px) scale(1)', opacity: 0, offset: 0 },
            { opacity: 1, offset: 0.12 },
            { transform: 'translate(0, 0) scale(1)', offset: 0.45 },
            { transform: 'translate(0, 0) scale(0.82)', offset: 0.52 },
            { transform: 'translate(0, 0) scale(1)', opacity: 1, offset: 0.62 },
            { transform: 'translate(0, 0) scale(1)', opacity: 1, offset: 0.88 },
            { transform: 'translate(0, 0) scale(1)', opacity: 0, offset: 1 },
        ], { duration: 2600, iterations: Infinity, easing: 'ease-in-out' }))
        animations.push(ripple.value.animate([
            { transform: 'scale(0.2)', opacity: 0, offset: 0 },
            { transform: 'scale(0.2)', opacity: 0.8, offset: 0.52 },
            { transform: 'scale(2.4)', opacity: 0, offset: 0.85 },
            { transform: 'scale(2.4)', opacity: 0, offset: 1 },
        ], { duration: 2600, iterations: Infinity }))

        return
    }

    if (gesture === 'swipe') {
        const reach = Math.min(box.width * 0.32, 130)
        animations.push(pointer.value.animate([
            { transform: 'translate(' + reach + 'px, 0)', opacity: 0, offset: 0 },
            { transform: 'translate(' + reach + 'px, 0)', opacity: 1, offset: 0.15 },
            { transform: 'translate(' + (-reach) + 'px, 0)', opacity: 1, offset: 0.6 },
            { transform: 'translate(' + (-reach) + 'px, 0)', opacity: 0, offset: 0.75 },
            { transform: 'translate(' + (-reach) + 'px, 0)', opacity: 0, offset: 1 },
        ], { duration: 2200, iterations: Infinity, easing: 'cubic-bezier(.45,0,.2,1)' }))

        return
    }

    const vertical = props.aid.axis === 'y'
    const distance = vertical ? Math.min(box.height * 0.5, 90) : Math.min(box.width * 0.5, 160)
    const move = (amount) => (vertical ? 'translate(0, ' + amount + 'px)' : 'translate(' + amount + 'px, 0)')
    animations.push(pointer.value.animate([
        { transform: move(0) + ' translate(40px, 50px)', opacity: 0, offset: 0 },
        { transform: move(0), opacity: 1, offset: 0.15 },
        { transform: move(0) + ' scale(0.85)', offset: 0.25 },
        { transform: move(distance) + ' scale(0.85)', offset: 0.7 },
        { transform: move(distance) + ' scale(1)', opacity: 1, offset: 0.8 },
        { transform: move(distance), opacity: 0, offset: 1 },
    ], { duration: 3000, iterations: Infinity, easing: 'ease-in-out' }))
    const thickness = vertical ? Math.min(box.width - 8, 160) : Math.max(18, Math.min(box.height - 10, 80))
    trail.value.style.width = vertical ? thickness + 'px' : '0px'
    trail.value.style.height = vertical ? '0px' : thickness + 'px'
    trail.value.style.left = vertical ? (-thickness / 2) + 'px' : '0px'
    trail.value.style.top = vertical ? '0px' : (-thickness / 2) + 'px'
    const size = (amount) => (vertical ? { height: amount + 'px' } : { width: amount + 'px' })
    animations.push(trail.value.animate([
        { ...size(0), opacity: 0, offset: 0 },
        { ...size(0), opacity: 0, offset: 0.24 },
        { ...size(0), opacity: 1, offset: 0.25 },
        { ...size(distance), opacity: 1, offset: 0.7 },
        { ...size(distance), opacity: 1, offset: 0.85 },
        { ...size(distance), opacity: 0, offset: 1 },
    ], { duration: 3000, iterations: Infinity, easing: 'ease-in-out' }))
}

watch([rect, pointer], () => play(), { flush: 'post' })

onBeforeUnmount(() => animations.forEach((animation) => animation.cancel()))
</script>

<style>
.tour-cursor {
    filter: drop-shadow(0 4px 6px rgb(0 0 0 / 0.35));
}

.tour-finger {
    background: rgb(255 255 255 / 0.55);
    box-shadow: 0 0 0 2px rgb(255 255 255 / 0.9), 0 6px 18px rgb(0 0 0 / 0.35);
    backdrop-filter: blur(2px);
}

.tour-ripple {
    border: 3px solid var(--color-lavoro-green);
    opacity: 0;
}

.tour-drag-trail {
    background: rgb(37 99 255 / 0.22);
    border: 2px dashed var(--color-lavoro-blue);
    opacity: 0;
}
</style>
