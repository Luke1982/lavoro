<template>
    <div ref="stage" class="pointer-events-none fixed inset-0 z-[10002]" aria-hidden="true" />
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { findSpot, findSpotAll, prefersReducedMotion, visibleRect } from '@/Tours/engine'

/**
 * A copy of a real card that lifts off its spot and flies to another one: a
 * werkbon onto a monteur's day, an afspraak onto tomorrow. It is a picture of
 * the drag, never the drag itself — nothing is planned or copied.
 *
 * `land: "event"` turns the card into a new afspraak where it lands; `"same"`
 * keeps it as it is, at the same time of day when it lands on another day.
 */
const props = defineProps({
    aid: { type: Object, required: true },
})

const stage = ref(null)
let alive = true
let nodes = []
let animations = []

function cleanUp() {
    animations.forEach((animation) => animation.cancel())
    nodes.forEach((node) => node.remove())
    animations = []
    nodes = []
}

function solidBackground(element) {
    for (let node = element; node && node !== document.documentElement; node = node.parentElement) {
        const color = getComputedStyle(node).backgroundColor
        if (color && color !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(color)) return color
    }

    return 'white'
}

function strip(node) {
    if (!(node instanceof Element)) return
    ;[...node.attributes].forEach((attribute) => {
        if (attribute.name === 'id' || attribute.name.startsWith('data-')) node.removeAttribute(attribute.name)
    })
    ;[...node.children].forEach(strip)
}

function makeClone(source, box) {
    const clone = source.cloneNode(true)
    strip(clone)
    Object.assign(clone.style, {
        position: 'fixed',
        left: box.left + 'px',
        top: box.top + 'px',
        right: 'auto',
        bottom: 'auto',
        width: box.width + 'px',
        height: box.height + 'px',
        margin: '0',
        transformOrigin: '0 0',
        boxSizing: 'border-box',
        backgroundColor: solidBackground(source),
        borderRadius: getComputedStyle(source).borderRadius === '0px' ? '12px' : getComputedStyle(source).borderRadius,
        boxShadow: '0 18px 40px rgb(0 0 0 / 0.35), 0 0 0 2px rgb(198 255 0 / 0.85)',
        opacity: '0',
        overflow: 'hidden',
        cursor: 'grabbing',
    })
    stage.value.appendChild(clone)
    nodes.push(clone)

    return clone
}

/** Where on the target the copy comes down, and how small it has to get to fit. */
function landing(source, from, target) {
    const box = visibleRect(target)
    const fromCell = source.closest('[data-day-iso]')
    if (props.aid.land === 'same' && fromCell && target.matches('[data-day-iso]')) {
        const cellBox = fromCell.getBoundingClientRect()

        return { x: box.left + (from.left - cellBox.left), y: box.top + (from.top - cellBox.top), scale: 1, box }
    }
    if (props.aid.land === 'event') {
        return { x: box.left + Math.min(24, box.width * 0.1), y: box.top + Math.max(4, (box.height - from.height) / 2), scale: Math.min(1, (box.height - 8) / from.height), box }
    }
    const scale = Math.max(0.3, Math.min(1, box.width / from.width, box.height / from.height))

    return { x: box.left + (box.width - from.width * scale) / 2, y: box.top + (box.height - from.height * scale) / 2, scale, box }
}

function makeLanded(spot, from) {
    const block = document.createElement('div')
    const width = Math.min(150, Math.max(90, spot.box.width * 0.45))
    const height = Math.max(34, Math.min(spot.box.height - 16, 96))
    Object.assign(block.style, {
        position: 'fixed',
        left: (spot.box.left + Math.min(24, spot.box.width * 0.1)) + 'px',
        top: (spot.box.top + (spot.box.height - height) / 2) + 'px',
        width: width + 'px',
        height: height + 'px',
        opacity: '0',
    })
    block.className = 'tour-landed'
    block.innerHTML = '<span class="tour-landed-title"></span><span class="tour-landed-sub"></span>'
    block.querySelector('.tour-landed-title').textContent = props.aid.label ?? 'Nieuwe afspraak'
    block.querySelector('.tour-landed-sub').textContent = from.label ?? ''
    stage.value.appendChild(block)
    nodes.push(block)

    return block
}

function makePulse(spot) {
    const pulse = document.createElement('div')
    pulse.className = 'tour-landing-pulse'
    Object.assign(pulse.style, {
        position: 'fixed',
        left: spot.box.left + 'px',
        top: spot.box.top + 'px',
        width: spot.box.width + 'px',
        height: spot.box.height + 'px',
        opacity: '0',
    })
    stage.value.appendChild(pulse)
    nodes.push(pulse)

    return pulse
}

function path(dx, dy, scale) {
    const frames = []
    const lift = Math.min(120, Math.max(50, Math.hypot(dx, dy) * 0.25))
    for (let index = 0; index <= 8; index++) {
        const t = index / 8
        const x = dx * t
        const y = dy * t - Math.sin(Math.PI * t) * lift - 6 * (1 - t)
        const size = 1.05 + (scale - 1.05) * t
        frames.push({
            transform: 'translate(' + x + 'px, ' + y + 'px) scale(' + size + ') rotate(' + (Math.sin(Math.PI * t) * -3) + 'deg)',
            offset: 0.12 + t * 0.58,
            opacity: 0.97,
        })
    }

    return frames
}

async function fly() {
    if (!alive || !stage.value) return
    cleanUp()
    const source = findSpot(props.aid.from)
    const targets = findSpotAll(props.aid.to)
    if (!source || !targets.length) return

    const from = source.getBoundingClientRect()
    const reduced = prefersReducedMotion()
    const duration = reduced ? 2600 : 3200
    const finished = []

    targets.slice(0, 6).forEach((target, index) => {
        const spot = landing(source, from, target)
        const clone = makeClone(source, from)
        const dx = spot.x - from.left
        const dy = spot.y - from.top
        const vanish = props.aid.land === 'event'
        const delay = index * 260

        const frames = reduced
            ? [
                { transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(' + spot.scale + ')', opacity: 0, offset: 0 },
                { transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(' + spot.scale + ')', opacity: 0.95, offset: 0.2 },
                { transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(' + spot.scale + ')', opacity: 0.95, offset: 0.85 },
                { transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(' + spot.scale + ')', opacity: 0, offset: 1 },
            ]
            : [
                { transform: 'translate(0, 0) scale(1)', opacity: 0, offset: 0 },
                { transform: 'translate(0, -6px) scale(1.05)', opacity: 0.97, offset: 0.1 },
                ...path(dx, dy, spot.scale),
                { transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(' + spot.scale + ')', opacity: vanish ? 0 : 0.97, offset: 0.78 },
                { transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(' + spot.scale + ')', opacity: vanish ? 0 : 0.97, offset: 0.92 },
                { transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(' + spot.scale + ')', opacity: 0, offset: 1 },
            ]
        const animation = clone.animate(frames, { duration, delay, easing: 'cubic-bezier(.45,.05,.25,1)', fill: 'forwards' })
        animations.push(animation)
        finished.push(animation.finished.catch(() => {}))

        const pulse = makePulse(spot)
        animations.push(pulse.animate([
            { opacity: 0, transform: 'scale(1)', offset: 0 },
            { opacity: 0, transform: 'scale(1)', offset: 0.69 },
            { opacity: 1, transform: 'scale(1)', offset: 0.72 },
            { opacity: 0, transform: 'scale(1.06)', offset: 0.95 },
            { opacity: 0, transform: 'scale(1.06)', offset: 1 },
        ], { duration, delay, fill: 'forwards' }))

        if (vanish) {
            const block = makeLanded(spot, {})
            animations.push(block.animate([
                { opacity: 0, transform: 'scale(0.9)', offset: 0 },
                { opacity: 0, transform: 'scale(0.9)', offset: 0.7 },
                { opacity: 1, transform: 'scale(1)', offset: 0.76 },
                { opacity: 1, transform: 'scale(1)', offset: 0.93 },
                { opacity: 0, transform: 'scale(1)', offset: 1 },
            ], { duration, delay, fill: 'forwards' }))
        }
    })

    await Promise.all(finished)
    if (!alive) return
    setTimeout(fly, 500)
}

onMounted(() => requestAnimationFrame(fly))

onBeforeUnmount(() => {
    alive = false
    cleanUp()
})
</script>

<style>
.tour-landed {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 2px;
    padding: 4px 10px;
    border-radius: 8px;
    border-left: 4px solid var(--color-lavoro-blue);
    background: rgb(231 239 254);
    color: rgb(8 16 32);
    box-shadow: 0 10px 24px rgb(0 0 0 / 0.3), 0 0 0 2px rgb(198 255 0 / 0.8);
    transform-origin: 0 50%;
}

.tour-landed-title {
    font-size: 12px;
    font-weight: 600;
    line-height: 1.2;
}

.tour-landed-sub {
    font-size: 10px;
    color: rgb(75 85 99);
}

.tour-landing-pulse {
    border-radius: 10px;
    box-shadow: inset 0 0 0 3px var(--color-lavoro-green), 0 0 24px 4px rgb(198 255 0 / 0.45);
}
</style>
