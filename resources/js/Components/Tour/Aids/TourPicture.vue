<template>
    <div ref="frame" class="tour-picture pointer-events-none fixed left-0 top-0 z-[10002]" :style="frameStyle" aria-hidden="true">
        <div class="tour-picture-inner overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/10 dark:bg-slate-900 dark:ring-white/10"
            :style="{ transform: 'scale(' + scale + ')' }">
            <component :is="scene" :variant="aid.variant ?? 'default'" />
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { computePosition, flip, offset, shift } from '@floating-ui/dom'
import { findSpot } from '@/Tours/engine'
import SceneOrderMenu from '@/Components/Tour/Scenes/SceneOrderMenu.vue'
import SceneEventMenu from '@/Components/Tour/Scenes/SceneEventMenu.vue'
import SceneExecution from '@/Components/Tour/Scenes/SceneExecution.vue'
import SceneExecutionButtons from '@/Components/Tour/Scenes/SceneExecutionButtons.vue'
import SceneRoster from '@/Components/Tour/Scenes/SceneRoster.vue'
import SceneOverride from '@/Components/Tour/Scenes/SceneOverride.vue'
import ScenePlanButton from '@/Components/Tour/Scenes/ScenePlanButton.vue'
import SceneCopyDate from '@/Components/Tour/Scenes/SceneCopyDate.vue'
import SceneCopyWeek from '@/Components/Tour/Scenes/SceneCopyWeek.vue'
import SceneRoute from '@/Components/Tour/Scenes/SceneRoute.vue'
import SceneTrayPlace from '@/Components/Tour/Scenes/SceneTrayPlace.vue'
import SceneTrayDrop from '@/Components/Tour/Scenes/SceneTrayDrop.vue'
import SceneTrayChoice from '@/Components/Tour/Scenes/SceneTrayChoice.vue'

/**
 * A small animated picture of a screen or menu that is not on the page right
 * now: the menu behind the right mouse button, the roster on somebody's profile,
 * the dialog that asks before planning over a day off. Drawn beside the spot it
 * belongs to, or in the middle when it has none, and never under the card.
 */
const SCENES = {
    'order-menu': SceneOrderMenu,
    'event-menu': SceneEventMenu,
    execution: SceneExecution,
    'execution-buttons': SceneExecutionButtons,
    roster: SceneRoster,
    override: SceneOverride,
    'plan-button': ScenePlanButton,
    'copy-date': SceneCopyDate,
    'copy-week': SceneCopyWeek,
    route: SceneRoute,
    'tray-place': SceneTrayPlace,
    'tray-drop': SceneTrayDrop,
    'tray-choice': SceneTrayChoice,
}

const props = defineProps({
    aid: { type: Object, required: true },
    card: { type: Object, default: null },
})

const frame = ref(null)
const position = ref({ x: -9999, y: -9999 })
const scale = ref(1)
let raf = null
let busy = false

const scene = computed(() => SCENES[props.aid.scene] ?? null)

const frameStyle = computed(() => ({
    transform: 'translate3d(' + position.value.x + 'px, ' + position.value.y + 'px, 0)',
}))

function freeArea() {
    const card = props.card
    const area = { top: 16, bottom: window.innerHeight - 16, left: 16, right: window.innerWidth - 16 }
    if (!card) return area
    if (card.top > window.innerHeight / 2) area.bottom = card.top - 16
    else if (card.bottom < window.innerHeight / 2) area.top = card.bottom + 16

    return area
}

async function place() {
    raf = requestAnimationFrame(place)
    if (busy || !frame.value) return
    busy = true
    try {
        const area = freeArea()
        const natural = frame.value.firstElementChild
        const width = natural.offsetWidth
        const height = natural.offsetHeight
        scale.value = Math.min(1, (area.right - area.left) / width, (area.bottom - area.top) / height)
        const shown = { width: width * scale.value, height: height * scale.value }
        const near = props.aid.near ? findSpot(props.aid.near) : null
        if (near) {
            const { x, y } = await computePosition(near, frame.value, {
                strategy: 'fixed',
                placement: 'right-start',
                middleware: [
                    offset(20),
                    flip({ fallbackPlacements: ['left-start', 'bottom', 'top'] }),
                    shift({ padding: { top: area.top, left: area.left, right: window.innerWidth - area.right, bottom: window.innerHeight - area.bottom }, crossAxis: true }),
                ],
            })
            position.value = { x, y }
        } else {
            position.value = {
                x: (window.innerWidth - shown.width) / 2,
                y: area.top + Math.max(0, (area.bottom - area.top - shown.height) / 2),
            }
        }
    } finally {
        busy = false
    }
}

onMounted(() => { raf = requestAnimationFrame(place) })
onBeforeUnmount(() => cancelAnimationFrame(raf))
</script>

<style>
.tour-picture-inner {
    transform-origin: 0 0;
    animation: tour-picture-in 0.45s cubic-bezier(.2, .9, .3, 1.2) both;
}

@keyframes tour-picture-in {
    from {
        opacity: 0;
        translate: 0 12px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .tour-picture-inner {
        animation: none;
    }

    .tour-picture * {
        animation-duration: 0s !important;
        animation-iteration-count: 1 !important;
    }
}
</style>
