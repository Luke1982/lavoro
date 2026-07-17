import { ref, computed } from "vue";

// Distance past the scroll edge before a flip is committed, and the point the
// pill stops travelling.
const PULL_THRESHOLD = 90;
const MAX_PULL = 130;
// Movement before the gesture commits to an axis, so a vertical scroll never
// reads as a horizontal pull.
const AXIS_LOCK = 10;

/**
 * "Pull past the edge to change week." The pull is measured from the point the
 * content runs out of horizontal scroll, not from where the finger landed, so
 * panning across the content never counts towards a flip. A vertical scroll is
 * left alone entirely.
 *
 * @param scrollEl   ref to the scroll container (its scrollLeft defines the edge)
 * @param canGoForward getter -> boolean; a forward pull is ignored when false
 * @param onFlip     called with -1 (previous) or 1 (next) when a pull commits
 */
export function usePullToFlip(scrollEl, { canGoForward, onFlip }) {
    const pull = ref(0);
    let startX = null;
    let startY = null;
    let lastX = null;
    let axis = null;
    let anchorX = null;

    const pullProgress = computed(() => Math.min(1, Math.abs(pull.value) / PULL_THRESHOLD));
    const pullArmed = computed(() => Math.abs(pull.value) >= PULL_THRESHOLD);

    function atLeftEdge() {
        return (scrollEl.value?.scrollLeft ?? 0) <= 0;
    }

    function atRightEdge() {
        const el = scrollEl.value;
        if (!el) return true;
        return el.scrollLeft >= el.scrollWidth - el.clientWidth - 1;
    }

    function reset() {
        pull.value = 0;
        anchorX = null;
    }

    function hardReset() {
        reset();
        startX = null;
        lastX = null;
        axis = null;
    }

    function onTouchStart(e) {
        if (e.touches.length !== 1) {
            hardReset();
            return;
        }
        startX = lastX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        axis = null;
        reset();
    }

    function onTouchMove(e) {
        if (startX === null || e.touches.length !== 1) {
            reset();
            return;
        }
        const x = e.touches[0].clientX;
        const y = e.touches[0].clientY;

        if (axis === null) {
            const dx = Math.abs(x - startX);
            const dy = Math.abs(y - startY);
            if (dx < AXIS_LOCK && dy < AXIS_LOCK) {
                lastX = x;
                return;
            }
            axis = dx > dy ? "x" : "y";
        }
        if (axis === "y") {
            reset();
            lastX = x;
            return;
        }

        const movingRight = x > lastX;
        lastX = x;

        // The pull begins the moment the content can scroll no further in the
        // direction of travel; before that, the gesture is an ordinary pan.
        const stuck = (movingRight && atLeftEdge()) || (!movingRight && atRightEdge());
        if (anchorX === null) {
            if (!stuck) return;
            anchorX = x;
        }

        const travelled = x - anchorX;
        const wantsForward = travelled < 0;
        if (wantsForward && canGoForward && !canGoForward()) {
            reset();
            return;
        }
        // Scrolling back off the edge abandons the pull.
        if ((travelled > 0 && !atLeftEdge()) || (travelled < 0 && !atRightEdge())) {
            reset();
            return;
        }
        pull.value = Math.max(-MAX_PULL, Math.min(MAX_PULL, travelled));
    }

    function onTouchEnd() {
        if (pullArmed.value) onFlip(pull.value > 0 ? -1 : 1);
        hardReset();
    }

    return { pull, pullArmed, pullProgress, onTouchStart, onTouchMove, onTouchEnd };
}
