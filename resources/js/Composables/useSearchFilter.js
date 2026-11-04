import { ref, computed, toValue } from 'vue'

/**
 * A search box over a list that is already in the browser.
 *
 * @param {Array|import('vue').Ref<Array>|() => Array} items
 * @param {(item: any, query: string) => boolean} matchFn  Gets the query trimmed and lowercased; never called for an empty one.
 */
export function useSearchFilter(items, matchFn) {
    const searchQuery = ref('')

    const filteredItems = computed(() => {
        const query = searchQuery.value.trim().toLowerCase()
        const list = toValue(items)
        if (!query) return list
        return list.filter(item => matchFn(item, query))
    })

    return {
        searchQuery,
        filteredItems,
    }
}
