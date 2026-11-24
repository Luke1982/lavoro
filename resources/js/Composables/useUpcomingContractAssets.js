import { onMounted, ref, toValue, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useQueryParams } from '@/Composables/useQueryParams'
import { browserStorage } from '@/Utilities/browserStorage'

const FILTERS = ['only', 'mixed', 'hide']

/**
 * Machines under a running maintenance contract, in the upcoming inspections and on their map:
 * only those, mixed in with the rest, or left out.
 *
 * When the settings hide them for everyone there is nothing to choose. Otherwise whoever may
 * see contracts decides. The server builds the list, so the choice lives in the query string
 * as `contracts=only|hide`, like any other filter; mixed is the default and has none. This
 * browser remembers it per user, so arriving without it (from the menu) brings back what was
 * chosen last.
 *
 * @param {import('vue').MaybeRefOrGetter<{filter: string, choosable: boolean}>} state
 * @param {object} visitOptions  Added to every visit, e.g. `preserveState: false` for a page
 *     that draws its content only once.
 */
export function useUpcomingContractAssets(state, visitOptions = {}) {
    const userId = usePage().props.auth?.user?.id ?? 'guest'
    const key = 'upcomingContractAssets_' + userId
    const formerKey = 'upcomingHideContractAssets_' + userId
    const { visit } = useQueryParams()

    const load = (filter, options = {}) => visit(
        { contracts: filter === 'mixed' ? null : filter },
        { ...visitOptions, ...options },
    )

    const remembered = () => {
        const filter = browserStorage.get(key)
        if (FILTERS.includes(filter)) {
            return filter
        }
        return browserStorage.get(formerKey) === '1' ? 'hide' : 'mixed'
    }

    /** Follows the buttons at once; the list follows once it has loaded with that choice. */
    const filter = ref(toValue(state).filter)

    watch(() => toValue(state).filter, (value) => { filter.value = value })
    watch(filter, (value) => {
        if (value !== toValue(state).filter) {
            browserStorage.set(key, value)
            load(value)
        }
    })

    onMounted(() => {
        const { choosable, filter: current } = toValue(state)
        const chosen = remembered()

        if (choosable && current === 'mixed' && chosen !== 'mixed') {
            load(chosen, { replace: true })
        }
    })

    return { filter }
}
