/**
 * The browser's back and forward buttons, heard before Inertia hears them.
 *
 * Inertia swaps the page as soon as it hears that the history moved, and a page
 * that listens itself is gone by then: listeners on the window run in the order
 * they were added, whether or not they capture, and Inertia's was added when the
 * app started. This one is installed before Inertia, so the page that is open
 * can still say no.
 */
let guard = null
let leaving = null

export function installHistoryGuard() {
    window.addEventListener('popstate', (event) => {
        if (guard && guard(event) === false) event.stopImmediatePropagation()
    })
}

/**
 * Lets the open page decide on the next move through the history: returning
 * false keeps it. Returns the function that stops guarding.
 */
export function guardHistory(decide) {
    guard = decide

    return () => {
        if (guard === decide) guard = null
    }
}

/**
 * Lets the open page answer for a way out that has to be asked about before it
 * starts (see confirmLeaving). `ask` gets the path that is about to be visited
 * and returns whether the person agrees. Returns the function that stops it.
 */
export function guardLeaving(ask) {
    leaving = ask

    return () => {
        if (leaving === ask) leaving = null
    }
}

/**
 * Asks the open page whether it may be left for `path`. Logging out tears down
 * location tracking, push and the caches before it visits anything; asked only
 * at that visit, a no would keep the person logged in with all of that gone.
 * A yes covers the visit to `path` that follows, so it is not asked twice.
 */
export function confirmLeaving(path) {
    return leaving ? leaving(path) : true
}
