import { computed, onBeforeUnmount, reactive, watch } from 'vue'
import { useTabBar } from '@/Composables/useTabBar.js'

/**
 * What lies along the bottom of the screen: the menu bar on a phone, and the
 * bars that come and go above it or, on a computer, along the bottom edge — a
 * selection, an announcement, the question about notifications. Whatever floats
 * in a bottom corner stands on top of all of them instead of disappearing
 * behind one.
 *
 * A bar reports itself with useBottomBar(); a floating thing reads floor.
 */
const reach = reactive(new Map())

const { clearance, tucked } = useTabBar()

/** How many pixels above the bottom edge is taken: by the highest bar, or by the menu bar. */
const floor = computed(() => Math.max(clearance.value, ...reach.values()))

export function useBottomBars() {
    return { floor }
}

/**
 * Reports the element in `target` as a bar along the bottom for as long as it
 * is on the screen. How high it reaches is its distance from the bottom edge
 * plus its height, both read from the layout: a bar still sliding in already
 * counts at the place it is sliding to. One hidden at this screen size takes
 * no room.
 */
export function useBottomBar(target) {
    const key = Symbol('bottom bar')

    const measure = () => {
        const element = target.value
        if (!element?.getClientRects().length) {
            reach.delete(key)
            return
        }
        reach.set(key, (parseFloat(getComputedStyle(element).bottom) || 0) + element.offsetHeight)
    }

    watch(target, (element, _previous, onCleanup) => {
        measure()
        if (!element) return
        const observer = new ResizeObserver(measure)
        observer.observe(element)
        onCleanup(() => observer.disconnect())
    }, { immediate: true, flush: 'post' })

    watch([clearance, tucked], measure, { flush: 'post' })
    window.addEventListener('resize', measure)

    onBeforeUnmount(() => {
        window.removeEventListener('resize', measure)
        reach.delete(key)
    })
}
