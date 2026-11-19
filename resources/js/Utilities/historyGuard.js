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
