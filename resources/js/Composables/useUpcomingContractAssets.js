import { onMounted, ref, toValue, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useQueryParams } from '@/Composables/useQueryParams'
import { browserStorage } from '@/Utilities/browserStorage'

/**
 * Machines under a running maintenance contract, in the upcoming inspections and on their map.
 *
 * When the settings hide them for everyone there is nothing to choose. Otherwise whoever may
 * see contracts decides. The server builds the list, so the choice lives in the query string
 * as `contracts=hide`, like any other filter; this browser remembers it per user, so arriving
 * without it (from the menu) brings back what was chosen last.
 *
 * @param {import('vue').MaybeRefOrGetter<{hidden: boolean, choosable: boolean}>} state
 * @param {object} visitOptions  Added to every visit, e.g. `preserveState: false` for a page
 *     that draws its content only once.
 */
export function useUpcomingContractAssets(state, visitOptions = {}) {
    const key = 'upcomingHideContractAssets_' + (usePage().props.auth?.user?.id ?? 'guest')
    const { visit } = useQueryParams()

    const load = (hidden, options = {}) => visit({ contracts: hidden ? 'hide' : null }, { ...visitOptions, ...options })

    /** Follows the switch at once; the list follows once it has loaded with or without them. */
    const shown = ref(!toValue(state).hidden)

    watch(() => toValue(state).hidden, (hidden) => { shown.value = !hidden })
    watch(shown, (value) => {
        const hidden = !value

        if (hidden !== toValue(state).hidden) {
            browserStorage.set(key, hidden ? '1' : '0')
            load(hidden)
        }
    })

    onMounted(() => {
        const { choosable, hidden } = toValue(state)

        if (choosable && !hidden && browserStorage.get(key) === '1') {
            load(true, { replace: true })
        }
    })

    return { shown }
}
