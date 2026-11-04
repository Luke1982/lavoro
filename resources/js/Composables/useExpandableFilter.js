import { ref, computed } from 'vue'
import { useSearchFilter } from '@/Composables/useSearchFilter'

/**
 * Search-filtered list capped at maxVisible items, with a show more/less toggle.
 *
 * @param {Array|import('vue').Ref<Array>|() => Array} items
 * @param {(item: any, query: string) => boolean} matchFn
 * @param {number} maxVisible
 */
export function useExpandableFilter(items, matchFn, maxVisible = 4) {
    const { searchQuery, filteredItems } = useSearchFilter(items, matchFn)
    const isExpanded = ref(false)

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
