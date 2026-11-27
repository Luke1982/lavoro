import { computed, ref } from 'vue'
import axios from 'axios'
import { debounce } from 'lodash'
import { nlCurrency } from '@/Utilities/Utilities'

/**
 * Zoeken in wat je kunt aanbieden, terwijl je typt: producten en materialen, of
 * alleen één van de twee. De keuzes staan klaar voor een ComboBox, met de prijs
 * erbij en bij een product hoeveel materialen er standaard meekomen; elke keuze
 * draagt ook het hele resultaat, voor CatalogOption.
 */
export function useQuoteCatalog(quoteId, only = null) {
    const results = ref([])
    const searching = ref(false)

    const options = computed(() => results.value.map(item => ({
        id: item.key,
        name: (only ? '' : (item.type === 'product' ? 'Product: ' : 'Materiaal: '))
            + `${item.name}, ${nlCurrency(item.price)}`
            + (item.defaults?.length ? ` (+${item.defaults.length} materialen)` : ''),
        item,
    })))

    const search = debounce(async (q) => {
        if (!q) return
        searching.value = true
        try {
            results.value = (await axios.get(`/quotes/${quoteId}/catalog`, { params: { q, only } })).data
        } finally {
            searching.value = false
        }
    }, 200)

    const find = (key) => results.value.find(item => item.key === key) ?? null

    return { options, searching, search, find }
}
