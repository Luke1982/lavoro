import { ref } from 'vue'
import axios from 'axios'

/**
 * Hybrid combo search: passes initialOptions through when isAjax is false.
 * When isAjax is true, replaces options with server results on each query.
 *
 * @param {string} resource  - 'customers' | 'materials' | 'products'
 * @param {Array}  initialOptions - eagerly-loaded options (empty in AJAX mode)
 * @param {boolean} isAjax  - true when count exceeded the server-side threshold
 */
export function useComboSearch(resource, initialOptions, isAjax) {
    const options = ref([...initialOptions])
    const searching = ref(false)

    async function search(q) {
        if (!q) return
        searching.value = true
        try {
            const { data } = await axios.get(`/combo/${resource}`, { params: { q } })
            options.value = data
        } finally {
            searching.value = false
        }
    }

    return {
        options,
        searching,
        search: isAjax ? search : () => {},
    }
}
