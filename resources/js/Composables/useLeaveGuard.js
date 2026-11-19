import { onBeforeUnmount } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { guardHistory } from '@/Utilities/historyGuard'

/**
 * Asks before work that is not saved yet is lost: going to another page in the
 * app, closing or reloading the tab, and the browser's back and forward
 * buttons. A visit that stays on this page, such as deferred props arriving,
 * is not leaving.
 *
 * Back and forward have already moved the address by the time the browser says
 * so. Staying puts this page's entry back on top, before Inertia hears of the
 * move (see historyGuard).
 *
 * The page's own address comes from the page, not from the address bar: while
 * this page is being set up, the bar still shows the page it came from.
 */
export function useLeaveGuard(hasUnsavedWork, question) {
    const ownPath = new URL(usePage().url, window.location.origin).pathname
    const mayLeave = () => !hasUnsavedWork() || window.confirm(question)
    let here = null

    const remember = () => {
        if (window.location.pathname === ownPath) {
            here = { state: window.history.state, url: window.location.href }
        }
    }

    const stopBefore = router.on('before', (event) => {
        const visit = event.detail.visit
        if (visit.method !== 'get' || visit.url.pathname === ownPath) return true

        return mayLeave()
    })

    const stopNavigate = router.on('navigate', remember)

    function onHistoryMove() {
        if (window.location.pathname === ownPath || mayLeave()) return true

        window.history.pushState(here?.state ?? null, '', here?.url ?? ownPath)

        return false
    }

    function onUnload(event) {
        if (!hasUnsavedWork()) return
        event.preventDefault()
        event.returnValue = ''
    }

    remember()
    const stopHistory = guardHistory(onHistoryMove)
    window.addEventListener('beforeunload', onUnload)

    onBeforeUnmount(() => {
        stopBefore()
        stopNavigate()
        stopHistory()
        window.removeEventListener('beforeunload', onUnload)
    })
}
