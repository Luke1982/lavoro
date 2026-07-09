import { ref, computed, toValue } from 'vue'

/**
 * Search-filtered list capped at maxVisible items, with a show more/less toggle.
 *
 * @param {Array|import('vue').Ref<Array>|() => Array} items
 * @param {(item: any, query: string) => boolean} matchFn
 * @param {number} maxVisible
 */
export function useExpandableFilter(items, matchFn, maxVisible = 4) {
    const searchQuery = ref('')
    const isExpanded = ref(false)

    const filteredItems = computed(() => {
        const query = searchQuery.value.trim().toLowerCase()
        const list = toValue(items)
        if (!query) return list
        return list.filter(item => matchFn(item, query))
    })

    const visibleItems = computed(() =>
        isExpanded.value ? filteredItems.value : filteredItems.value.slice(0, maxVisible)
    )

    return {
        searchQuery,
        isExpanded,
        filteredItems,
        visibleItems,
    }
}
