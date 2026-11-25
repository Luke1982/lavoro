/**
 * The assistant's one-time filter on an overview (`?filter=<uuid>`).
 *
 * A link from the assistant opens a list on exactly the records it counted. The
 * list's own filters remembered in this browser would narrow that again, and a
 * search or a change of filter would visit the page without it and show
 * everything; both make the list disagree with the count the assistant gave.
 * So a page with the filter skips restoring what it remembered, and every visit
 * the page makes carries the filter along: searching on top of it narrows
 * further, and the bar above the list says so.
 */
const PARAM = 'filter'

/** What a status filter says for every status, like the server's `all`. */
const EVERY_STATUS = 'all'

function currentSearch() {
    return typeof window === 'undefined' ? '' : window.location.search
}

/** Whether the page was opened with the assistant's filter. */
export function opensWithAssistantFilter(search = currentSearch()) {
    return new URLSearchParams(search).has(PARAM)
}

/** The parameters of a visit to the same list, with the filter the address carries. */
export function withAssistantFilter(params, search = currentSearch()) {
    const filter = new URLSearchParams(search).get(PARAM)

    if (filter === null || PARAM in params) {
        return params
    }

    return { ...params, [PARAM]: filter }
}

/**
 * What a list's status filter opens on: every status with the assistant's
 * filter, which was counted over all of them, the list's own default without.
 * A status that differs from this has to be sent along on every visit, or the
 * server falls back to it; and only a status that differs counts as filtered.
 */
export function defaultStatusWith(usual, search = currentSearch()) {
    return opensWithAssistantFilter(search) ? EVERY_STATUS : usual
}
