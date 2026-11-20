import { onBeforeUnmount } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { guardHistory, guardLeaving } from '@/Utilities/historyGuard'

/**
 * Asks before work that is not saved yet is lost: going to another page in the
 * app, closing or reloading the tab, the browser's back and forward buttons,
 * and logging out. A visit that stays on this page, such as deferred props
 * arriving, is not leaving.
 *
 * Back and forward have already moved the address by the time the browser says
 * so. Staying puts this page's entry back on top, before Inertia hears of the
 * move (see historyGuard). A move to another entry of this same page is leaving
 * too: Inertia puts that entry's page back and sets this one up afresh. Only an
 * entry without a page of its own, a #fragment on this page, leaves it alone.
 *
 * Logging out asks first and visits later (see confirmLeaving); the path it
 * was given a yes for is let through once it is visited.
 *
 * The page's own address comes from the page, not from the address bar: while
 * this page is being set up, the bar still shows the page it came from.
 */
export function useLeaveGuard(hasUnsavedWork, question) {
    const ownPath = new URL(usePage().url, window.location.origin).pathname
    const mayLeave = () => !hasUnsavedWork() || window.confirm(question)
    let here = null
    let agreedTo = null

    const remember = () => {
        if (window.location.pathname === ownPath) {
            here = { state: window.history.state, url: window.location.href }
        }
    }

    const stopBefore = router.on('before', (event) => {
        const visit = event.detail.visit
        const path = visit.url.pathname
        if (visit.method !== 'get' || path === ownPath || path === agreedTo) return true

        return mayLeave()
    })

    const stopNavigate = router.on('navigate', remember)

    function onHistoryMove(event) {
        const fragmentHere = event.state === null && window.location.pathname === ownPath
        if (fragmentHere || mayLeave()) return true

        window.history.pushState(here?.state ?? null, '', here?.url ?? ownPath)

        return false
    }

    function onLeaving(path) {
        agreedTo = mayLeave() ? path : null

        return agreedTo !== null
    }

    function onUnload(event) {
        if (!hasUnsavedWork()) return
        event.preventDefault()
        event.returnValue = ''
    }

    remember()
    const stopHistory = guardHistory(onHistoryMove)
    const stopLeaving = guardLeaving(onLeaving)
    window.addEventListener('beforeunload', onUnload)

    onBeforeUnmount(() => {
        stopBefore()
        stopNavigate()
        stopHistory()
        stopLeaving()
        window.removeEventListener('beforeunload', onUnload)
    })
}
