import { COLOURS, labelHeight } from '@/Components/Images/annotationShapes.js'

/** Every shape a demo draws has an id with this in front, so it is never taken for one of the person's. */
const DEMO = 'demo-'

export const isDemoShape = (id) => typeof id === 'string' && id.startsWith(DEMO)

const PALETTE = COLOURS.map((option) => option.value)

const ease = (t) => (t < 0.5 ? 2 * t * t : 1 - (-2 * t + 2) ** 2 / 2)

/** How far t is from a to b, from 0 to 1. */
const stretch = (t, from, to) => Math.min(1, Math.max(0, (t - from) / (to - from)))

const between = (from, to, share) => ({ x: from.x + (to.x - from.x) * share, y: from.y + (to.y - from.y) * share })

/** Fades the last stretch of every loop, so it starts again from an empty photo. */
const fading = (t) => 1 - stretch(t, 0.86, 1)

const centre = (region) => ({ x: region.x + region.width / 2, y: region.y + region.height * 0.48 })

/**
 * The hand at moment t: it comes in from down and to the right until it reaches
 * `start` at `down`, presses from `down` to `up` while it is at `at`, and is gone
 * from `gone` on.
 */
function hand(t, region, { start, at = start, down, up, gone }) {
    if (t >= gone) return null
    const from = { x: start.x + region.width * 0.18, y: start.y + region.height * 0.22 }

    return { ...(t < down ? between(from, start, ease(stretch(t, 0, down))) : at), pressed: t >= down && t <= up }
}

function arrowEnds(region) {
    const middle = centre(region)

    return {
        tail: { x: middle.x - region.width * 0.24, y: middle.y + region.height * 0.22 },
        head: { x: middle.x - region.width * 0.02, y: middle.y + region.height * 0.02 },
    }
}

/** An arrow drawn `share` of the way from its tail to its head, moved by `offset`. */
function arrow(name, { region, unit, colour }, { share = 1, offset = { x: 0, y: 0 } } = {}) {
    const { tail, head } = arrowEnds(region)
    const tip = between(tail, head, share)

    return {
        id: DEMO + name,
        type: 'arrow',
        colour,
        size: unit,
        points: [tail.x + offset.x, tail.y + offset.y, tip.x + offset.x, tip.y + offset.y],
    }
}

/**
 * What the rondleiding through the annotator acts out on the photo itself: a
 * hand that circles something with the marker, draws an arrow, places a label,
 * drags an arrow aside, tries the colours and pulls in the crop frame.
 *
 * Each demo is a loop of `duration` milliseconds. `frame(t, view)` says, for a
 * moment t between 0 and 1, which shapes are on the photo, where the hand is
 * and whether it is pressing, all in the photo's own pixels; `view` is the part
 * of the photo on screen, the size a stroke has there and the chosen colour.
 * The annotator draws that and nothing else: none of it is ever part of the
 * drawing that is saved. `still` is the moment shown, unmoving, to someone who
 * asked for less motion.
 */
export const DEMOS = {
    draw: {
        duration: 3800,
        still: 0.7,
        frame(t, { region, unit, colour }) {
            const middle = centre(region)
            const radius = Math.min(region.width, region.height) * 0.2
            const start = -Math.PI * 0.6
            const sweep = Math.PI * 2.15
            const drawn = ease(stretch(t, 0.15, 0.65))
            const steps = Math.max(1, Math.round(drawn * 72))
            const points = []

            for (let index = 0; index <= steps; index++) {
                const angle = start + sweep * drawn * (index / steps)
                const wobble = 1 + 0.05 * Math.sin(angle * 3)
                points.push(middle.x + radius * 1.3 * wobble * Math.cos(angle), middle.y + radius * wobble * Math.sin(angle))
            }

            const tip = { x: points.at(-2), y: points.at(-1) }

            return {
                shapes: drawn > 0 ? [{ id: DEMO + 'draw', type: 'draw', colour, size: unit, points }] : [],
                cursor: hand(t, region, { start: tip, down: 0.15, up: 0.65, gone: 0.8 }),
                opacity: fading(t),
            }
        },
    },

    arrow: {
        duration: 3400,
        still: 0.7,
        frame(t, view) {
            const drawn = ease(stretch(t, 0.18, 0.55))
            const { tail, head } = arrowEnds(view.region)

            return {
                shapes: drawn > 0.08 ? [arrow('arrow', view, { share: drawn })] : [],
                cursor: hand(t, view.region, { start: tail, at: between(tail, head, drawn), down: 0.18, up: 0.55, gone: 0.78 }),
                opacity: fading(t),
            }
        },
    },

    text: {
        duration: 4400,
        still: 0.75,
        frame(t, { region, unit, colour }) {
            const middle = centre(region)
            const spot = { x: middle.x + region.width * 0.04, y: middle.y - region.height * 0.22 }
            const word = 'Lekkage hier'
            const typed = word.slice(0, Math.ceil(word.length * stretch(t, 0.28, 0.7)))
            const label = { id: DEMO + 'text', type: 'text', colour, size: unit, text: typed, x: spot.x, y: spot.y - labelHeight(unit) / 2 }

            return {
                shapes: t >= 0.2 ? [label] : [],
                cursor: hand(t, region, { start: spot, down: 0.16, up: 0.22, gone: 0.26 }),
                opacity: fading(t),
            }
        },
    },

    colours: {
        duration: 3600,
        still: 0.2,
        frame(t, view) {
            const colour = PALETTE[Math.floor(t * PALETTE.length) % PALETTE.length]

            return { shapes: [arrow('colours', { ...view, colour })] }
        },
    },

    move: {
        duration: 3800,
        still: 0.7,
        frame(t, view) {
            const { region } = view
            const { tail, head } = arrowEnds(region)
            const grip = between(tail, head, 0.5)
            const moved = ease(stretch(t, 0.2, 0.62))
            const offset = { x: region.width * 0.14 * moved, y: -region.height * 0.08 * moved }

            return {
                shapes: [arrow('move', view, { offset })],
                selected: t >= 0.16 ? DEMO + 'move' : null,
                cursor: hand(t, region, { start: grip, at: { x: grip.x + offset.x, y: grip.y + offset.y }, down: 0.16, up: 0.62, gone: 0.8 }),
                opacity: fading(t),
            }
        },
    },

    crop: {
        duration: 4000,
        still: 0.7,
        frame(t, { region }) {
            const pulled = ease(stretch(t, 0.18, 0.6)) * (1 - ease(stretch(t, 0.88, 1)))
            const inset = { x: region.width * 0.16 * pulled, y: region.height * 0.13 * pulled }
            const frame = {
                x: region.x + inset.x,
                y: region.y + inset.y,
                width: region.width - inset.x * 2,
                height: region.height - inset.y * 2,
            }
            const corner = { x: frame.x + frame.width, y: frame.y + frame.height }

            return {
                crop: frame,
                cursor: hand(t, region, { start: corner, down: 0.18, up: 0.6, gone: 0.8 }),
            }
        },
    },
}
