import { ref, watch } from 'vue'
import { computePosition, autoUpdate, flip, offset, shift } from '@floating-ui/dom'
import { onClickOutside, onKeyStroke } from '@vueuse/core'

const FLOATING = { position: 'fixed', zIndex: 9999 }

/**
 * A panel that floats beside the button that opens it, teleported to <body> so
 * no scrolling ancestor can clip it. The panel needs tabindex="-1": it takes
 * the focus when it opens, or the keyboard could never reach it at the end of
 * the document. Escape closes it through `close()`, which hands the focus back
 * to the button; a click elsewhere only closes it, because that click already
 * put the focus where the person wanted it.
 */
export function usePopover(button, panel, placement = 'bottom-start') {
    const open = ref(false)
    const style = ref({ ...FLOATING })

    watch(panel, (element, _previous, onCleanup) => {
        if (!element || !button.value) return
        element.focus({ preventScroll: true })
        onCleanup(autoUpdate(button.value, element, async () => {
            const { x, y } = await computePosition(button.value, element, {
                placement,
                strategy: 'fixed',
                middleware: [offset(8), flip(), shift({ padding: 8 })],
            })
            style.value = { ...FLOATING, top: `${y}px`, left: `${x}px` }
        }))
    })

    function close() {
        open.value = false
        button.value?.focus()
    }

    onClickOutside(panel, () => { open.value = false }, { ignore: [button] })
    onKeyStroke('Escape', () => { if (open.value) close() })

    return { open, style, close }
}
