import { ref, watch } from 'vue'
import { computePosition, autoUpdate, flip, offset, shift } from '@floating-ui/dom'
import { onClickOutside, onKeyStroke } from '@vueuse/core'

const FLOATING = { position: 'fixed', zIndex: 9999 }

/**
 * A panel that floats beside the button that opens it, teleported to <body> so
 * no scrolling ancestor can clip it. Clicking outside it or pressing Escape
 * closes it; the button itself is left to toggle `open`.
 */
export function usePopover(button, panel, placement = 'bottom-start') {
    const open = ref(false)
    const style = ref({ ...FLOATING })

    watch(panel, (element, _previous, onCleanup) => {
        if (!element || !button.value) return
        onCleanup(autoUpdate(button.value, element, async () => {
            const { x, y } = await computePosition(button.value, element, {
                placement,
                strategy: 'fixed',
                middleware: [offset(8), flip(), shift({ padding: 8 })],
            })
            style.value = { ...FLOATING, top: `${y}px`, left: `${x}px` }
        }))
    })

    onClickOutside(panel, () => { open.value = false }, { ignore: [button] })
    onKeyStroke('Escape', () => { open.value = false })

    return { open, style }
}
