<template>
    <TourPicker />

    <Teleport to="body">
        <div v-if="visible" data-tour-layer class="tour-layer" :class="{ 'tour-reduced': reduced }">
            <svg class="pointer-events-none fixed inset-0 z-[10000] h-full w-full" aria-hidden="true">
                <path ref="dimPath" class="tour-dim" fill-rule="evenodd" @pointerdown.prevent="nudgeCard"
                    @click.prevent />
            </svg>
            <div ref="ring" class="tour-ring pointer-events-none fixed left-0 top-0 z-[10001]" />
            <TourMarks v-if="tourState.marks.length" />

            <TourAid v-if="tourState.aid && tourState.phase === 'step'" :key="tourState.aid.key" :aid="tourState.aid"
                :card="mode === 'float' ? null : cardBox" />

            <div ref="cardWrap" class="tour-card-wrap fixed z-[10003]" :class="cardClasses" :style="cardStyle">
                <TourCard ref="card" />
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch, nextTick } from 'vue'
import { computePosition, flip, offset, shift } from '@floating-ui/dom'
import TourPicker from '@/Components/Tour/TourPicker.vue'
import TourCard from '@/Components/Tour/TourCard.vue'
import TourAid from '@/Components/Tour/TourAid.vue'
import TourMarks from '@/Components/Tour/TourMarks.vue'
import {
    tourState, nextStep, previousStep, stopTour, togglePause, toggleMute, nudgeCard, prefersReducedMotion,
    visibleRect,
} from '@/Tours/engine'

/**
 * The layer the rondleiding is drawn on: a dimmed screen with a hole where the
 * spot is, a ring around the hole, the visual aid, and the card that talks.
 *
 * It sits directly under <body>, outside the application, so the dialogs here
 * (Headless UI) count it as one of their own and keep their focus and their
 * clicks; popovers and context menus are told about it by name.
 *
 * Clicks on the dimmed part are caught, so a stray click cannot change the page
 * under a step; clicks inside the hole go through, so somebody can try out what
 * they are being shown. Caught, not stopped: a popover decides on the pointerdown
 * whether a click was outside it, and has to see this one to know it was ours.
 */
const PADDING = 8
const RADIUS = 14
const TWEEN = 560

const dimPath = ref(null)
const ring = ref(null)
const cardWrap = ref(null)
const card = ref(null)
const reduced = ref(prefersReducedMotion())
const mode = ref('center')
const cardBox = ref(null)
const cardPosition = ref({ x: 0, y: 0 })

const visible = computed(() => !!tourState.run || tourState.phase === 'finished')

let raf = null
let display = null
let tween = null
let lastElements = undefined
let positioning = false
let desiredPosition = null

const FLOATING = ['float', 'corner', 'center', 'side']
const SIDE_MIN = 300
const sideWidth = ref(null)

const cardClasses = computed(() => ({
    'tour-dock-bottom': mode.value === 'dock-bottom',
    'tour-dock-top': mode.value === 'dock-top',
    'tour-floating': FLOATING.includes(mode.value),
}))

const cardStyle = computed(() => {
    if (!FLOATING.includes(mode.value)) return {}
    const style = { transform: 'translate3d(' + cardPosition.value.x + 'px, ' + cardPosition.value.y + 'px, 0)' }
    if (mode.value === 'side' && sideWidth.value) style.width = sideWidth.value + 'px'

    return style
})

function unionRect(elements) {
    const rects = elements.filter((element) => element.isConnected).map(visibleRect).filter((rect) => rect.width > 0 && rect.height > 0)
    if (!rects.length) return null
    const left = Math.min(...rects.map((rect) => rect.left))
    const top = Math.min(...rects.map((rect) => rect.top))
    const right = Math.max(...rects.map((rect) => rect.right))
    const bottom = Math.max(...rects.map((rect) => rect.bottom))

    return {
        x: Math.max(4, left - PADDING),
        y: Math.max(4, top - PADDING),
        w: Math.min(window.innerWidth - 4, right + PADDING) - Math.max(4, left - PADDING),
        h: Math.min(window.innerHeight - 4, bottom + PADDING) - Math.max(4, top - PADDING),
    }
}

/**
 * The hole and the picture drawn beside it, as one box: the card goes next to
 * both, so the spot, the picture of what happens there and the words about it
 * stay together instead of the card ending up across the screen.
 */
function withPicture(hole) {
    const picture = document.querySelector('[data-tour-layer] .tour-picture-inner')
    if (!picture || tourState.aid?.type !== 'picture') return hole
    const box = picture.getBoundingClientRect()
    if (box.width < 2 || box.height < 2) return hole
    const left = Math.min(hole.x, box.left)
    const top = Math.min(hole.y, box.top)

    return {
        x: left,
        y: top,
        w: Math.max(hole.x + hole.w, box.right) - left,
        h: Math.max(hole.y + hole.h, box.bottom) - top,
    }
}

function emptyRect() {
    return { x: window.innerWidth / 2, y: window.innerHeight / 2, w: 0, h: 0 }
}

function ease(t) {
    return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2
}

function lerpRect(from, to, t) {
    return {
        x: from.x + (to.x - from.x) * t,
        y: from.y + (to.y - from.y) * t,
        w: from.w + (to.w - from.w) * t,
        h: from.h + (to.h - from.h) * t,
    }
}

function roundedHole({ x, y, w, h }) {
    if (w < 2 || h < 2) return ''
    const r = Math.min(RADIUS, w / 2, h / 2)

    return 'M' + (x + r) + ' ' + y + 'H' + (x + w - r) + 'A' + r + ' ' + r + ' 0 0 1 ' + (x + w) + ' ' + (y + r)
        + 'V' + (y + h - r) + 'A' + r + ' ' + r + ' 0 0 1 ' + (x + w - r) + ' ' + (y + h)
        + 'H' + (x + r) + 'A' + r + ' ' + r + ' 0 0 1 ' + x + ' ' + (y + h - r)
        + 'V' + (y + r) + 'A' + r + ' ' + r + ' 0 0 1 ' + (x + r) + ' ' + y + 'Z'
}

function chooseMode(hole, cardHeight) {
    const width = window.innerWidth
    const height = window.innerHeight
    if (tourState.phase !== 'step' && tourState.phase !== 'moving') return width >= 768 ? 'center' : 'dock-bottom'
    const hasHole = hole && hole.w > 2
    const pictured = tourState.aid?.type === 'picture'
    if (width < 768) {
        return hasHole && hole.y + hole.h / 2 > height * 0.55 ? 'dock-top' : 'dock-bottom'
    }
    if (!hasHole) return pictured ? 'dock-bottom' : 'center'
    if (pictured && tourState.aid.near) return 'float'
    if (pictured) {
        return hole.y + hole.h > height - cardHeight - 48 && hole.y > cardHeight + 48 ? 'dock-top' : 'dock-bottom'
    }
    if (hole.w * hole.h > width * height * 0.4) {
        return Math.max(width - hole.x - hole.w, hole.x) - 24 >= SIDE_MIN ? 'side' : 'corner'
    }

    return 'float'
}

async function placeCard(hole) {
    const element = cardWrap.value
    if (!element) return
    const width = element.offsetWidth
    const height = element.offsetHeight
    mode.value = chooseMode(hole, height)
    const box = element.getBoundingClientRect()
    const previous = cardBox.value
    if (!previous || Math.abs(previous.top - box.top) + Math.abs(previous.left - box.left) + Math.abs(previous.height - box.height) > 6) {
        cardBox.value = { top: box.top, left: box.left, width: box.width, height: box.height, bottom: box.bottom, right: box.right }
    }

    if (mode.value === 'center') {
        desiredPosition = { x: (window.innerWidth - width) / 2, y: (window.innerHeight - height) / 2 }
    } else if (mode.value === 'corner') {
        desiredPosition = { x: window.innerWidth - width - 24, y: window.innerHeight - height - 24 }
    } else if (mode.value === 'side') {
        const right = window.innerWidth - hole.x - hole.w
        const room = Math.max(right, hole.x) - 24
        sideWidth.value = Math.min(400, room)
        const x = right >= hole.x ? hole.x + hole.w + 12 : hole.x - sideWidth.value - 12
        const y = Math.min(Math.max(12, hole.y + hole.h - height), window.innerHeight - height - 12)
        desiredPosition = { x, y }
    } else if (mode.value === 'float' && !positioning) {
        positioning = true
        const around = withPicture(hole)
        const reference = {
            getBoundingClientRect: () => ({
                x: around.x, y: around.y, left: around.x, top: around.y, width: around.w, height: around.h,
                right: around.x + around.w, bottom: around.y + around.h,
            }),
        }
        try {
            const shown = tourState.shown
            const placement = (shown && tourState.run?.chapters[shown.chapterIndex]?.steps[shown.stepIndex]?.placement) ?? 'bottom'
            const { x, y } = await computePosition(reference, element, {
                strategy: 'fixed',
                placement,
                middleware: [
                    offset(18),
                    flip({ padding: 12, fallbackPlacements: ['bottom', 'top', 'left', 'right'] }),
                    shift({ padding: 12, crossAxis: true }),
                ],
            })
            desiredPosition = { x, y }
        } finally {
            positioning = false
        }
    }

    if (desiredPosition && FLOATING.includes(mode.value)) {
        const current = cardPosition.value
        const far = Math.abs(current.x - desiredPosition.x) + Math.abs(current.y - desiredPosition.y) > 2000
        const factor = reduced.value || far || (current.x === 0 && current.y === 0) ? 1 : 0.2
        cardPosition.value = {
            x: current.x + (desiredPosition.x - current.x) * factor,
            y: current.y + (desiredPosition.y - current.y) * factor,
        }
    }
}

function loop(now) {
    raf = requestAnimationFrame(loop)
    if (!dimPath.value) return

    const elements = tourState.target?.elements ?? null
    const goal = (elements && unionRect(elements)) || emptyRect()
    if (elements !== lastElements) {
        lastElements = elements
        tween = reduced.value || !display ? null : { from: display, startedAt: now }
    }
    if (tween) {
        const t = Math.min(1, (now - tween.startedAt) / TWEEN)
        display = lerpRect(tween.from, goal, ease(t))
        if (t >= 1) tween = null
    } else {
        display = goal
    }

    const width = window.innerWidth
    const height = window.innerHeight
    dimPath.value.setAttribute('d', 'M0 0H' + width + 'V' + height + 'H0Z' + roundedHole(display))

    const ringStyle = ring.value?.style
    if (ringStyle) {
        const shown = display.w > 2 && tourState.phase === 'step'
        ringStyle.opacity = shown ? '1' : '0'
        ringStyle.width = display.w + 'px'
        ringStyle.height = display.h + 'px'
        ringStyle.transform = 'translate3d(' + display.x + 'px, ' + display.y + 'px, 0)'
        ringStyle.borderRadius = Math.min(RADIUS, display.w / 2, display.h / 2) + 'px'
    }

    placeCard(display.w > 2 ? display : null)
}

function isTyping(target) {
    if (!(target instanceof Element) || target.closest('[data-tour-layer]')) return false

    return target.closest('input, textarea, select, [contenteditable="true"]') !== null
}

function onKeydown(event) {
    if (!tourState.run || isTyping(event.target)) return
    const onButton = event.target instanceof Element && event.target.closest('[data-tour-layer] button')
    const handlers = {
        ArrowRight: nextStep,
        ArrowLeft: previousStep,
        Escape: stopTour,
        m: toggleMute,
        M: toggleMute,
        ' ': onButton ? null : togglePause,
    }
    const handler = handlers[event.key]
    if (!handler) return
    event.preventDefault()
    event.stopPropagation()
    handler()
}

function start() {
    if (raf) return
    display = null
    lastElements = undefined
    cardPosition.value = { x: 0, y: 0 }
    raf = requestAnimationFrame(loop)
}

function halt() {
    cancelAnimationFrame(raf)
    raf = null
}

watch(visible, async (shown) => {
    if (!shown) return halt()
    reduced.value = prefersReducedMotion()
    await nextTick()
    start()
    card.value?.root?.focus({ preventScroll: true })
}, { immediate: true })

onMounted(() => window.addEventListener('keydown', onKeydown, true))

onBeforeUnmount(() => {
    halt()
    window.removeEventListener('keydown', onKeydown, true)
    stopTour()
})
</script>

<style>
.tour-dim {
    fill: rgb(8 16 32 / 0.66);
    pointer-events: auto;
    cursor: default;
}

.tour-ring {
    box-shadow: 0 0 0 2px var(--color-lavoro-blue), 0 0 0 6px rgb(37 99 255 / 0.25), 0 0 32px 6px rgb(198 255 0 / 0.25);
    transition: opacity 0.25s ease;
    animation: tour-ring-pulse 2.4s ease-in-out infinite;
}

@keyframes tour-ring-pulse {
    50% {
        box-shadow: 0 0 0 2px var(--color-lavoro-blue), 0 0 0 9px rgb(37 99 255 / 0.12), 0 0 44px 10px rgb(198 255 0 / 0.32);
    }
}

.tour-card-wrap.tour-floating {
    left: 0;
    top: 0;
    width: 25rem;
    max-width: calc(100vw - 24px);
}

.tour-card-wrap.tour-dock-bottom,
.tour-card-wrap.tour-dock-top {
    left: 12px;
    right: 12px;
}

.tour-card-wrap.tour-dock-bottom {
    bottom: max(12px, env(safe-area-inset-bottom));
}

.tour-card-wrap.tour-dock-top {
    top: max(12px, env(safe-area-inset-top));
}

@media (min-width: 768px) {
    .tour-card-wrap.tour-dock-bottom,
    .tour-card-wrap.tour-dock-top {
        left: 50%;
        right: auto;
        width: 28rem;
        transform: translateX(-50%);
    }

    .tour-card-wrap.tour-dock-bottom {
        bottom: 24px;
    }

    .tour-card-wrap.tour-dock-top {
        top: 24px;
    }
}

.tour-reduced .tour-ring {
    animation: none;
}

@media (prefers-reduced-motion: reduce) {
    .tour-ring {
        animation: none;
    }
}
</style>
