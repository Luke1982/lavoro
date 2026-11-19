import { computed, inject, reactive, watch } from 'vue'
import { useComboSearch } from '@/Composables/useComboSearch'
import { PICKED_MODELS_KEY } from './formulaEditor'

/**
 * The models a picker offers: the catalogue while it is small, search results
 * once it is not, and always the model already chosen, so it keeps its name
 * while the list holds something else.
 *
 * A model found by searching is not in the catalogue the page was given. It is
 * remembered in the editor's list of picked models, so the block, its summary
 * and the next search still know its name.
 */
export function useModelOptions(productOptions, productsUseAjax, chosenId) {
    const { options: searched, searching, search } = useComboSearch('products', productOptions, productsUseAjax)
    const picked = inject(PICKED_MODELS_KEY, null) ?? reactive([])

    watch(chosenId, (id) => {
        const found = searched.value.find(option => option.id === id)
        if (found && !productOptions.some(option => option.id === id) && !picked.some(option => option.id === id)) {
            picked.push({ id: found.id, name: found.name })
        }
    })

    const options = computed(() => {
        const list = [...searched.value]
        const chosen = [...productOptions, ...picked].find(option => option.id === chosenId())
        if (chosen && !list.some(option => option.id === chosen.id)) list.unshift(chosen)

        return list.map(option => ({ id: option.id, name: option.name }))
    })

    return { options, searching, search }
}
