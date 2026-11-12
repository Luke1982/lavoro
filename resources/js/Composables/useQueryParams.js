import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

/**
 * The query string as the one place a list keeps its state, so a refresh, a shared link
 * and the back button all show the same thing. `visit` changes some parameters and leaves
 * every other one exactly as it was; an empty value removes its parameter. Anything but a
 * page change starts again at page 1, because the old page number means nothing in a list
 * that was just filtered or sorted differently.
 */
export function useQueryParams() {
    const page = usePage()
    const url = computed(() => new URL(page.url, window.location.origin))
    const params = computed(() => Object.fromEntries(url.value.searchParams))

    function visit(changes, options = {}) {
        const next = new URL(url.value)

        for (const [key, value] of Object.entries(changes)) {
            if (value == null || value === '') {
                next.searchParams.delete(key)
            } else {
                next.searchParams.set(key, value)
            }
        }

        if (!('page' in changes)) {
            next.searchParams.delete('page')
        }

        router.get(next.pathname + next.search, {}, { preserveState: true, preserveScroll: true, ...options })
    }

    return { params, visit }
}
