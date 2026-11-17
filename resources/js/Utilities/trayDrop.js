import { watchEffect } from 'vue'
import { router } from '@inertiajs/vue3'
import {
    useTray, canPaste, isTrayDrag, readTrayDrag, pasteFromTray, pasteCarried, endTrayDrag,
} from '@/Composables/useTray.js'

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
 * Makes an element a place to paste things off the bakje.
 *
 *     v-tray-drop="{ target: { type: 'serviceorder', id: order.id }, label: 'op deze werkbon te zetten' }"
 *
 * Which kinds of thing it takes is the server's to say, by the target type. Where
 * one page has two places of the same type, `accepts` narrows each to its own:
 * keuringen and storingen are both pasted on a werkbon, in different blocks.
 *
 * While something it takes is being carried it fades in a dashed outline and a
 * label, so a person sees where it can go before letting go: "Loslaten om …" for
 * a drag, "Tik om …" for something picked up by tapping. On the paste it ripples
 * once. After the paste the page reloads, unless the binding brings its own
 * `onDone`.
 */
export const vTrayDrop = {
    mounted(el, binding) {
        const { state } = useTray()
        const tray = { binding, over: false, landed: false }
        el._tray = tray

        if (getComputedStyle(el).position === 'static') {
            el.style.position = 'relative'
        }

        const takes = (kind) => {
            const value = tray.binding.value
            return canPaste(kind, value?.target?.type) && (value?.accepts?.includes(kind) ?? true)
        }

        const accepting = () => !!state.carrying && takes(state.carrying.kind)

        const paint = () => {
            if (tray.landed) {
                el.setAttribute('data-tray-drop', 'landed')
                return
            }
            if (!accepting()) {
                tray.over = false
                el.setAttribute('data-tray-drop', 'idle')
                return
            }
            if (!tray.over) placeLabel(el)
            const verb = state.carrying.by === 'tap' ? 'Tik om ' : 'Loslaten om '
            el.setAttribute('data-tray-label', verb + (tray.binding.value?.label ?? 'hier te plakken'))
            el.setAttribute('data-tray-drop', tray.over ? 'over' : 'accepting')
        }

        tray.stop = watchEffect(paint)

        const land = (paste) => {
            const value = tray.binding.value
            tray.over = false
            tray.landed = true
            paint()
            clearTimeout(tray.landedTimer)
            tray.landedTimer = setTimeout(() => {
                tray.landed = false
                paint()
            }, 700)
            paste(value.target, value.onDone ?? (() => router.reload({ preserveScroll: true })))
        }

        tray.onOver = (event) => {
            if (!accepting() || !isTrayDrag(event)) return
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
            endTrayDrag()
            if (!payload || !takes(payload.kind)) return
            land((target, onDone) => pasteFromTray(payload.item_ids, target, onDone))
        }

        /** Picked up by tapping, the tap is the drop; it never reaches the buttons and links inside. */
        tray.onTap = (event) => {
            if (state.carrying?.by !== 'tap' || !accepting()) return
            event.preventDefault()
            event.stopPropagation()
            land(pasteCarried)
        }

        el.addEventListener('dragover', tray.onOver)
        el.addEventListener('dragleave', tray.onLeave)
        el.addEventListener('drop', tray.onDrop)
        el.addEventListener('click', tray.onTap, true)
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
        el.removeEventListener('click', tray.onTap, true)
    },
}
