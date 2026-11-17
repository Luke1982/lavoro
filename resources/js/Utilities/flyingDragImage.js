/**
 * Replaces the browser's faded drag image with a solid copy of the card that
 * floats just above the cursor, casts a shadow and tilts with the way it is
 * moved. It hovers clear of the cursor so a drop target's placeholder stays in
 * sight, and the drag itself stays a native one, so drop targets work as before.
 */
const TRANSPARENT_PIXEL = new Image()
TRANSPARENT_PIXEL.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'

const FRAME_MS = 1000 / 60
const LANDING_MS = 220
const FLOAT_GAP_PX = 14
const LIFT_SCALE = 1.05
const PERSPECTIVE_PX = 700
const MAX_TILT_DEG = 18
const MAX_SWAY_DEG = 7
const TILT_DEG_PER_PX_PER_MS = 9
const SWAY_DEG_PER_PX_PER_MS = 3
const MIN_SAMPLE_MS = 8
const VELOCITY_SMOOTHING = 0.45
const VELOCITY_DECAY_PER_FRAME = 0.8
const SETTLE_PER_FRAME = { tilt_x: 0.18, tilt_y: 0.18, sway: 0.12, scale: 0.2, lift: 0.2 }
const LEVEL = { tilt_x: 0, tilt_y: 0, sway: 0 }
const BACKGROUND = { light: 'rgb(255 255 255)', dark: 'rgb(30 41 59)' }

let current_flight = null

/**
 * Call from a dragstart handler. The card dims in its place until the drag
 * ends: a drop fades the copy out where it was let go, a cancelled drag flies
 * it back into its place.
 */
export function startFlyingDragImage(e) {
    current_flight?.remove()

    const source = e.currentTarget
    const home = source.getBoundingClientRect()
    const grab_x = e.clientX - home.left
    const grab_y = e.clientY - home.top
    const reduced_motion = matchMedia('(prefers-reduced-motion: reduce)').matches
    const dark = matchMedia('(prefers-color-scheme: dark)').matches

    const pointer = { x: e.clientX, y: e.clientY, time: performance.now(), velocity_x: 0, velocity_y: 0 }
    const pose = { ...LEVEL, scale: reduced_motion ? LIFT_SCALE : 1, lift: reduced_motion ? 1 : 0 }
    const listening = new AbortController()
    const listen_options = { capture: true, signal: listening.signal }
    const card = cloneCard(source, home, grab_x, dark)
    let last_frame = pointer.time
    let landed = false

    paint()
    document.body.append(card)
    e.dataTransfer.setDragImage(TRANSPARENT_PIXEL, 0, 0)
    source.style.opacity = '0.4'
    current_flight = { remove }

    let frame = requestAnimationFrame(render)
    document.addEventListener('dragover', follow, listen_options)
    document.addEventListener('drop', landDropped, listen_options)
    source.addEventListener('dragend', ev => land(ev.dataTransfer.dropEffect !== 'none'), { signal: listening.signal })

    /**
     * Once the drag is under way, a pointer that moves again means the drag
     * ended somewhere dragend could not reach: its card was taken off the page.
     */
    function follow(ev) {
        const now = performance.now()
        const elapsed = Math.max(MIN_SAMPLE_MS, now - pointer.time)
        pointer.velocity_x = mix(pointer.velocity_x, (ev.clientX - pointer.x) / elapsed, VELOCITY_SMOOTHING)
        pointer.velocity_y = mix(pointer.velocity_y, (ev.clientY - pointer.y) / elapsed, VELOCITY_SMOOTHING)
        Object.assign(pointer, { x: ev.clientX, y: ev.clientY, time: now })
        document.addEventListener('pointermove', landDropped, listen_options)
    }

    function render(now) {
        const frames = Math.max(0, now - last_frame) / FRAME_MS
        last_frame = now
        const tilt = reduced_motion ? 0 : TILT_DEG_PER_PX_PER_MS
        const sway = reduced_motion ? 0 : SWAY_DEG_PER_PX_PER_MS
        const target = {
            tilt_x: clamp(-pointer.velocity_y * tilt, MAX_TILT_DEG),
            tilt_y: clamp(pointer.velocity_x * tilt, MAX_TILT_DEG),
            sway: clamp(-pointer.velocity_x * sway, MAX_SWAY_DEG),
            scale: LIFT_SCALE,
            lift: 1,
        }

        for (const [key, rate] of Object.entries(SETTLE_PER_FRAME)) {
            pose[key] = mix(pose[key], target[key], 1 - (1 - rate) ** frames)
        }
        pointer.velocity_x *= VELOCITY_DECAY_PER_FRAME ** frames
        pointer.velocity_y *= VELOCITY_DECAY_PER_FRAME ** frames

        paint()
        frame = requestAnimationFrame(render)
    }

    function paint() {
        const top = pointer.y - grab_y - pose.lift * (home.height - grab_y + FLOAT_GAP_PX)
        card.style.transform = cardTransform(pointer.x - grab_x, top, pose)
        card.style.boxShadow = cardShadow(pose, dark)
    }

    function landDropped() {
        land(true)
    }

    function land(dropped) {
        if (landed) return
        landed = true
        stop()

        card.style.transition = ['transform', 'opacity', 'box-shadow']
            .map(property => `${property} ${LANDING_MS}ms cubic-bezier(.2, .8, .2, 1)`)
            .join(', ')

        if (dropped) {
            source.style.opacity = ''
            Object.assign(pose, LEVEL, { scale: 0.9 })
            paint()
            card.style.opacity = '0'
        } else {
            const place = source.isConnected ? source.getBoundingClientRect() : home
            card.style.transform = cardTransform(place.left, place.top, { ...LEVEL, scale: 1 })
            card.style.boxShadow = 'none'
        }

        setTimeout(remove, LANDING_MS)
    }

    function stop() {
        cancelAnimationFrame(frame)
        listening.abort()
    }

    function remove() {
        stop()
        card.remove()
        source.style.opacity = ''
        if (current_flight?.remove === remove) current_flight = null
    }
}

/**
 * A copy that looks like the card but is invisible to the page: nothing finds
 * it by id, data attribute or tour anchor, and it never catches the cursor.
 * Vue's scoped style attributes (data-v-*) stay, so it keeps its styling.
 */
function cloneCard(source, rect, grab_x, dark) {
    const card = source.cloneNode(true)

    for (const el of [card, ...card.querySelectorAll('*')]) {
        el.getAttributeNames()
            .filter(name => /^(id|title|draggable|data-(?!v-).*)$/.test(name))
            .forEach(name => el.removeAttribute(name))
    }

    card.classList.remove('transition')
    card.setAttribute('aria-hidden', 'true')

    Object.assign(card.style, {
        position: 'fixed',
        left: '0',
        top: '0',
        margin: '0',
        width: `${rect.width}px`,
        height: `${rect.height}px`,
        boxSizing: 'border-box',
        borderRadius: '0.5rem',
        background: dark ? BACKGROUND.dark : BACKGROUND.light,
        opacity: '0.97',
        pointerEvents: 'none',
        zIndex: '10000',
        transformOrigin: `${grab_x}px 100%`,
        willChange: 'transform',
    })

    return card
}

function cardTransform(left, top, { tilt_x, tilt_y, sway, scale }) {
    return `translate3d(${left}px, ${top}px, 0) perspective(${PERSPECTIVE_PX}px)`
        + ` rotateX(${tilt_x.toFixed(2)}deg) rotateY(${tilt_y.toFixed(2)}deg)`
        + ` rotateZ(${sway.toFixed(2)}deg) scale(${scale.toFixed(3)})`
}

function cardShadow({ tilt_x, tilt_y }, dark) {
    const strength = dark ? 0.6 : 0.35
    const offset_x = (-tilt_y * 0.9).toFixed(1)
    const offset_y = (22 + tilt_x * 0.6).toFixed(1)
    return `${offset_x}px ${offset_y}px 40px -10px rgb(15 23 42 / ${strength}),`
        + ` 0 6px 14px -6px rgb(15 23 42 / ${(strength * 0.8).toFixed(2)})`
}

function mix(from, to, amount) {
    return from + (to - from) * amount
}

function clamp(value, limit) {
    return Math.max(-limit, Math.min(limit, value))
}
