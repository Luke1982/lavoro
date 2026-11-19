<template>
    <ListFilters :filters="filters" :query="query" item-class="sm:flex-1 sm:min-w-36 sm:max-w-60"
        class="grid grid-cols-2 gap-2 sm:flex sm:flex-1 sm:flex-wrap" />
</template>

<script setup>
import { computed } from 'vue'
import ListFilters from '@/Components/UI/ListFilters.vue'

/**
 * The filters above the table of upcoming machines. Each one offers only the values the
 * window actually contains.
 *
 * Planning is the one filter that is in use from the start: the page opens on the machines
 * nobody planned yet, so emptying it has to be said out loud to the server as 'all'.
 */
const props = defineProps({
    /** { customers, productTypes, due }: the values present among the machines in the window. */
    options: { type: Object, required: true },
    /** The filters in effect, defaults included. */
    query: { type: Object, required: true },
})

const filters = computed(() => [
    { param: 'customer', placeholder: 'Klant', everything: 'Alle klanten', options: props.options.customers },
    { param: 'type', placeholder: 'Type', everything: 'Alle types', options: props.options.productTypes },
    { param: 'due', placeholder: 'Status', everything: 'Alle statussen', options: props.options.due },
    {
        param: 'tickets',
        placeholder: 'Storingen',
        everything: 'Met en zonder storingen',
        options: [
            { id: 'with', name: 'Met storingen' },
            { id: 'without', name: 'Zonder storingen' },
        ],
    },
    {
        param: 'planning',
        placeholder: 'Planning',
        everything: 'Gepland en niet gepland',
        emptied: 'all',
        options: [
            { id: 'unplanned', name: 'Niet gepland' },
            { id: 'planned', name: 'Al gepland' },
        ],
    },
])
</script>
