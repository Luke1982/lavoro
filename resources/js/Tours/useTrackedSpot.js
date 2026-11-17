import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { findSpot, visibleRect } from './engine'

/**
 * Follows where a spot is on screen, frame by frame, so an aid drawn over it
 * stays on it while the page scrolls underneath. Finds the spot again if the
 * element it had was replaced.
 */
export function useTrackedSpot(name) {
    const element = shallowRef(null)
    const rect = ref(null)
    let raf = null

    function measure() {
        raf = requestAnimationFrame(measure)
        if (!name) return
        if (!element.value?.isConnected) element.value = findSpot(name)
        if (!element.value) {
            rect.value = null

            return
        }
        const box = visibleRect(element.value)
        const previous = rect.value
        if (previous
            && Math.abs(previous.left - box.left) < 0.5 && Math.abs(previous.top - box.top) < 0.5
            && Math.abs(previous.width - box.width) < 0.5 && Math.abs(previous.height - box.height) < 0.5) return
        rect.value = { left: box.left, top: box.top, width: box.width, height: box.height, right: box.right, bottom: box.bottom }
    }

    onMounted(() => { raf = requestAnimationFrame(measure) })
    onBeforeUnmount(() => cancelAnimationFrame(raf))

    return { element, rect }
}
