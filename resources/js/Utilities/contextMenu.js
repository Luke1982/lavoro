import ContextMenu from '@imengyu/vue3-context-menu'
import { isInsideTourLayer } from '@/Tours/layer'

/**
 * Opens a right-click menu the way this application wants them: closed by a
 * click anywhere else, except a click on the rondleiding. Somebody pressing
 * "Volgende" there is not done with the menu they are being shown.
 */
export function showContextMenu(options) {
    return ContextMenu.showContextMenu({
        theme: prefersDark() ? 'default dark' : 'default',
        clickCloseOnOutside: false,
        onClickOnOutside: (event) => {
            if (!isInsideTourLayer(event.target)) ContextMenu.closeContextMenu()
        },
        ...options,
    })
}

function prefersDark() {
    return typeof window !== 'undefined' && window.matchMedia?.('(prefers-color-scheme: dark)').matches === true
}
