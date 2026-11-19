import { nextTick } from 'vue'
import { useTour, isDesktop, prefersReducedMotion } from '@/Composables/useTour'

/** A moment for the light to settle on a control before it moves, how long a glide takes, and the rest after it. */
const TOUR_DELAY_MS = 1200
const TOUR_GLIDE_MS = 1600
const TOUR_SETTLE_MS = 400
const ZOOM_TRANSITION_MS = 150
const TODAY_MARGIN_PX = 40
const TIMELINE_HEADER_PX = 24

/**
 * The project timeline in the rondleiding of a project. It works the timeline's
 * own controls live, a moment after the light is on them, so the viewer sees
 * what they do. Every action hands back an undo that puts the layout, both zoom
 * levels, the scroll position and what this browser session remembered back
 * exactly as they were. `timeline` is what ProjectTimeline owns.
 */
export function useTimelineTour(timeline) {
    const {
        scrollerRef, viewMode, zoomColPx, LANE_HEIGHT, LANE_HEIGHT_MIN, LANE_HEIGHT_MAX, ZOOM_MAX_COL_PX, minZoomColPx,
        LEFT_COL_PX, ZOOM_H_STORAGE_KEY, ZOOM_V_STORAGE_KEY, soChips, openBubble, closeBubble, showItemMenu, pointAnchor,
    } = timeline

    let tourChip = null

    useTour('project', {
        showTimelineWeeks: () => playForTour((motion) => {
            holdInPlace(motion, TOUR_SETTLE_MS)
            viewMode.value = 'week'
        }),
        showTimelineToday: () => playForTour(scrollToToday),
        zoomTimelineIn: () => playForTour((motion) => {
            holdInPlace(motion, TOUR_GLIDE_MS + TOUR_SETTLE_MS)
            glide(motion, zoomColPx, zoomTarget())
        }),
        raiseTimelineRows: () => playForTour((motion) => glide(motion, LANE_HEIGHT, rowsTarget())),
        openTimelineBubble: () => showForTour((chip, serviceOrder, x) => {
            openBubble('serviceorder', serviceOrder, pointAnchor(chip, x))

            return closeBubble
        }),
        openTimelineMenu: () => showForTour((chip, serviceOrder, x) => {
            const menu = showItemMenu('serviceorder', serviceOrder, pointAnchor(chip, x), x, chip.getBoundingClientRect().bottom - 4)

            return () => {
                if (menu && !menu.isClosed()) menu.closeMenu()
            }
        }),
    }, {
        spots: {
            'project.timeline.order': () => tourChip,
            'project.timeline.menu': () => document.querySelector('.mx-context-menu'),
        },
    })

    function playForTour(play) {
        if (!scrollerRef.value) return null
        const restore = keepTimelineView()
        const motion = { timer: null, frame: null, hold: null }
        motion.timer = setTimeout(() => play(motion), TOUR_DELAY_MS)

        return async () => {
            clearTimeout(motion.timer)
            cancelAnimationFrame(motion.frame)
            cancelAnimationFrame(motion.hold)
            await restore()
        }
    }

    /**
     * Keeps the middle of what is on the timeline where it is while the scale changes, so the
     * viewer sees the blocks spread out instead of sliding off the screen. Anchored on an item's
     * left edge: that edge is the item's date in every layout and at every zoom.
     */
    function holdInPlace(motion, duration) {
        const anchor = middleItem()
        if (!anchor) return
        const x = anchor.getBoundingClientRect().left
        const until = performance.now() + duration
        const follow = (now) => {
            const drift = anchor.isConnected ? anchor.getBoundingClientRect().left - x : 0
            if (Math.abs(drift) >= 1) scrollerRef.value?.scrollBy({ left: drift, behavior: 'instant' })
            if (now < until) motion.hold = requestAnimationFrame(follow)
        }
        motion.hold = requestAnimationFrame(follow)
    }

    function middleItem() {
        const items = [...(scrollerRef.value?.querySelectorAll('[data-tl-item]') ?? [])].filter((item) => visiblePart(item).width > 0)
        if (!items.length) return scrollerRef.value?.querySelector('#tl-today') ?? null
        const rects = items.map((item) => item.getBoundingClientRect())
        const middle = (Math.min(...rects.map((rect) => rect.left)) + Math.max(...rects.map((rect) => rect.right))) / 2

        return items.reduce((best, item, index) => (
            Math.abs(rects[index].left - middle) < Math.abs(best.getBoundingClientRect().left - middle) ? item : best
        ), items[0])
    }

    function showForTour(open) {
        const chip = tourOrderChip()
        if (!chip) return null
        const restore = keepTimelineView()
        revealChip(chip)
        roomBelow(chip)
        tourChip = chip
        const close = open(chip, soChips.value[Number(chip.dataset.tlOrder)].so, visiblePart(chip).centreX)

        return async () => {
            close()
            tourChip = null
            await restore()
        }
    }

    function keepTimelineView() {
        const scroller = scrollerRef.value
        const kept = {
            viewMode: viewMode.value,
            zoom: zoomColPx.value,
            laneHeight: LANE_HEIGHT.value,
            left: scroller.scrollLeft,
            top: scroller.scrollTop,
            storedZoom: sessionStorage.getItem(ZOOM_H_STORAGE_KEY),
            storedLaneHeight: sessionStorage.getItem(ZOOM_V_STORAGE_KEY),
        }

        return async () => {
            viewMode.value = kept.viewMode
            LANE_HEIGHT.value = kept.laneHeight
            await wait(50)
            zoomColPx.value = kept.zoom
            await nextTick()
            restoreStored(ZOOM_H_STORAGE_KEY, kept.storedZoom)
            restoreStored(ZOOM_V_STORAGE_KEY, kept.storedLaneHeight)
            await wait(ZOOM_TRANSITION_MS)
            scrollerRef.value?.scrollTo({ left: kept.left, top: kept.top, behavior: 'instant' })
        }
    }

    function restoreStored(key, value) {
        if (value === null) sessionStorage.removeItem(key)
        else sessionStorage.setItem(key, value)
    }

    function wait(milliseconds) {
        return new Promise((resolve) => setTimeout(resolve, milliseconds))
    }

    function glide(motion, value, target) {
        const from = value.value
        if (prefersReducedMotion()) {
            value.value = target
            return
        }
        const started = performance.now()
        const step = (now) => {
            const progress = Math.min(1, (now - started) / TOUR_GLIDE_MS)
            const eased = progress < 0.5 ? 2 * progress ** 2 : 1 - ((2 - 2 * progress) ** 2) / 2
            value.value = Math.round(from + (target - from) * eased)
            if (progress < 1) motion.frame = requestAnimationFrame(step)
        }
        motion.frame = requestAnimationFrame(step)
    }

    function zoomTarget() {
        const wider = Math.min(ZOOM_MAX_COL_PX, Math.round(zoomColPx.value * 2.5))

        return wider >= zoomColPx.value * 1.5 ? wider : minZoomColPx.value
    }

    function rowsTarget() {
        return LANE_HEIGHT.value <= LANE_HEIGHT_MAX - 30 ? LANE_HEIGHT_MAX : LANE_HEIGHT_MIN
    }

    /** The part of the scroller where the time axis shows: right of the sticky names, below the sticky months. */
    function canvasView() {
        const scroller = scrollerRef.value
        const rect = scroller.getBoundingClientRect()

        return {
            left: rect.left + LEFT_COL_PX,
            right: rect.left + scroller.clientWidth,
            top: rect.top + TIMELINE_HEADER_PX,
            bottom: rect.top + scroller.clientHeight,
        }
    }

    function visiblePart(element) {
        const view = canvasView()
        const rect = element.getBoundingClientRect()
        const left = Math.max(rect.left, view.left)
        const right = Math.min(rect.right, view.right)
        const top = Math.max(rect.top, view.top)
        const bottom = Math.min(rect.bottom, view.bottom)

        return {
            width: Math.max(0, right - left),
            height: Math.max(0, bottom - top),
            centreX: left + Math.max(0, right - left) / 2,
        }
    }

    /** The work order block most in view, or else the first one. */
    function tourOrderChip() {
        const chips = [...(scrollerRef.value?.querySelectorAll('[data-tl-order]') ?? [])]
        const area = (chip) => {
            const part = visiblePart(chip)

            return part.width * part.height
        }

        return chips.reduce((best, chip) => (area(chip) > area(best) ? chip : best), chips[0] ?? null)
    }

    function revealChip(chip) {
        const view = canvasView()
        const rect = chip.getBoundingClientRect()
        const part = visiblePart(chip)
        const left = part.width < 60 ? rect.left - view.left - 16 : 0
        const top = part.height < rect.height ? rect.top - view.top - 16 : 0
        if (left || top) scrollerRef.value.scrollBy({ left, top, behavior: 'instant' })
    }

    /**
     * On a phone the rondleiding's card covers the lower half of the screen. The block goes near
     * the top, so the balloon or the menu under it stays in sight.
     */
    function roomBelow(chip) {
        if (isDesktop()) return
        window.scrollBy({ top: chip.getBoundingClientRect().top - window.innerHeight * 0.15, behavior: 'instant' })
    }

    /**
     * What the "Vandaag" link does, without its anchor in the address: the browser brings the
     * marker just into view, as near as it can, clear of the sticky names.
     */
    function scrollToToday() {
        const today = scrollerRef.value?.querySelector('#tl-today')
        if (!today) return
        const view = canvasView()
        const x = today.getBoundingClientRect().left
        const left = x - TODAY_MARGIN_PX < view.left ? x - TODAY_MARGIN_PX - view.left
            : x + TODAY_MARGIN_PX > view.right ? x + TODAY_MARGIN_PX - view.right : 0
        if (left) scrollerRef.value.scrollBy({ left, behavior: 'smooth' })
    }
}
