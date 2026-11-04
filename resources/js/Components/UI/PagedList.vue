<template>
    <div>
        <TextInput v-if="hasControls" v-model="searchQuery" type="search" :icon-left="MagnifyingGlassIcon"
            :placeholder="searchPlaceholder" class="mb-3" />

        <slot v-if="filteredItems.length" :items="pageItems" />
        <p v-else-if="items.length" class="text-sm text-gray-400 dark:text-slate-500 italic">
            Geen {{ label }} gevonden voor "{{ searchQuery.trim() }}".
        </p>

        <div v-if="hasControls" class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <PageSizeSelect v-model="perPage" :total="filteredItems.length" :label="label" />
            <div v-if="pageCount > 1" class="ml-auto flex items-center gap-2 text-sm text-gray-600 dark:text-slate-400">
                <button type="button" :class="stepClasses" :disabled="page === 1" @click="page--"
                    aria-label="Vorige pagina">
                    <ChevronLeftIcon class="size-4" />
                </button>
                <span class="tabular-nums">{{ page }} / {{ pageCount }}</span>
                <button type="button" :class="stepClasses" :disabled="page === pageCount" @click="page++"
                    aria-label="Volgende pagina">
                    <ChevronRightIcon class="size-4" />
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useLocalStorage } from '@vueuse/core'
import { ChevronLeftIcon, ChevronRightIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import TextInput from '@/Components/UI/TextInput.vue'
import PageSizeSelect, { PAGE_SIZES } from '@/Components/UI/PageSizeSelect.vue'
import { useSearchFilter } from '@/Composables/useSearchFilter'
import { forMatching } from '@/Utilities/Utilities'

/**
 * A list that is already in the browser, searched and paged there. Search and
 * paging only appear once the list outgrows the smallest page; a short list is
 * just the list. The page itself is handed to the default slot as `items`.
 */
const props = defineProps({
    items: { type: Array, required: true },
    /** The fields an item can be found by, matched the way every picker matches. */
    searchKeys: { type: Array, required: true },
    /** What the items are called, plural: "Toon 10 van 57 locaties". */
    label: { type: String, required: true },
    searchPlaceholder: { type: String, default: 'Zoeken...' },
    /** Where the chosen page size is remembered, so it is picked once per list rather than per visit. */
    storageKey: { type: String, required: true },
})

const [smallestPage] = PAGE_SIZES
const stepClasses = 'rounded-md p-2 ring-1 ring-gray-200 dark:ring-slate-700 enabled:hover:bg-gray-50 dark:enabled:hover:bg-slate-800 disabled:opacity-40'

const perPage = useLocalStorage(props.storageKey, smallestPage)
/** Picking the chosen size again empties the box, like every ComboBox; the list then keeps to the smallest page. */
const pageSize = computed(() => perPage.value || smallestPage)

const { searchQuery, filteredItems } = useSearchFilter(() => props.items, (item, query) =>
    forMatching(props.searchKeys.map(key => item[key]).join(' ')).includes(forMatching(query)))

const page = ref(1)
const pageCount = computed(() => Math.max(1, Math.ceil(filteredItems.value.length / pageSize.value)))
const pageItems = computed(() =>
    filteredItems.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value)
)
const hasControls = computed(() => props.items.length > smallestPage)

watch([searchQuery, pageSize], () => { page.value = 1 })
watch(pageCount, (count) => { page.value = Math.min(page.value, count) })
</script>
