import { watchEffect } from 'vue'
import { router } from '@inertiajs/vue3'
import { useTray, isTrayDrag, readTrayDrag, pasteFromTray, endTrayDrag } from '@/Composables/useTray.js'

/** Room the label needs above a place to hang over its top edge. */
const LABEL_ROOM = 16

/** The nearest element around this one that scrolls or crops what sticks out of it. */
function clippingAncestor(el) {
    for (let parent = el.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
        if (getComputedStyle(parent).overflowY !== 'visible') return parent
    }
    return null
}

/**
 * The label hangs over the top edge of the place, unless whatever scrolls or
 * crops around it would cut it off there: then it sits just inside the edge.
 */
function placeLabel(el) {
    const ceiling = clippingAncestor(el)?.getBoundingClientRect().top ?? 0
    const room = el.getBoundingClientRect().top - ceiling
    el.setAttribute('data-tray-label-at', room < LABEL_ROOM ? 'inside' : 'above')
}

/**
 * Makes an element a place to drop things off the bakje.
 *
 *     v-tray-drop="{ accepts: ['ticket'], target: { type: 'serviceorder', id: order.id } }"
 *
 * While something it accepts is being dragged it fades in a dashed outline and a
 * label, so a person sees where they can let go before they do; on the drop it
 * ripples once. Everything else on the page stays as it is. After the paste the
 * page reloads, unless the binding brings its own `onDone`.
 */
export const vTrayDrop = {
    mounted(el, binding) {
        const { state } = useTray()
        const tray = { binding, over: false }
        el._tray = tray

        if (getComputedStyle(el).position === 'static') {
            el.style.position = 'relative'
        }

        const accepts = () => !!state.dragging && (tray.binding.value?.accepts ?? []).includes(state.dragging.kind)

        el.setAttribute('data-tray-label', binding.value?.label ?? 'Hier loslaten')

        const paint = () => {
            if (tray.landed) {
                el.setAttribute('data-tray-drop', 'landed')
                return
            }
            if (!accepts()) {
                tray.over = false
                el.setAttribute('data-tray-drop', 'idle')
                return
            }
            if (!tray.over) placeLabel(el)
            el.setAttribute('data-tray-drop', tray.over ? 'over' : 'accepting')
            el.setAttribute('data-tray-label', tray.binding.value?.label ?? 'Hier loslaten')
        }

        tray.stop = watchEffect(paint)

        tray.onOver = (event) => {
            if (!accepts() || !isTrayDrag(event)) return
            event.preventDefault()
            event.dataTransfer.dropEffect = 'copy'
            if (!tray.over) {
                tray.over = true
                paint()
            }
        }

        tray.onLeave = (event) => {
            if (el.contains(event.relatedTarget)) return
            tray.over = false
            paint()
        }

        tray.onDrop = (event) => {
            if (!isTrayDrag(event)) return
            event.preventDefault()
            event.stopPropagation()
            const payload = readTrayDrag(event)
            const value = tray.binding.value
            tray.over = false
            endTrayDrag()
            if (!payload || !value?.accepts?.includes(payload.kind)) return
            tray.landed = true
            paint()
            clearTimeout(tray.landedTimer)
            tray.landedTimer = setTimeout(() => {
                tray.landed = false
                paint()
            }, 700)
            pasteFromTray(payload.item_ids, value.target, value.onDone ?? (() => router.reload({ preserveScroll: true })))
        }

        el.addEventListener('dragover', tray.onOver)
        el.addEventListener('dragleave', tray.onLeave)
        el.addEventListener('drop', tray.onDrop)
    },

    updated(el, binding) {
        el._tray.binding = binding
    },

    unmounted(el) {
        const tray = el._tray
        tray.stop?.()
        clearTimeout(tray.landedTimer)
        el.removeEventListener('dragover', tray.onOver)
        el.removeEventListener('dragleave', tray.onLeave)
        el.removeEventListener('drop', tray.onDrop)
    },
}
