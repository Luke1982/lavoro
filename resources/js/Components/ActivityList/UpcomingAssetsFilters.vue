<template>
    <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-1 sm:flex-wrap">
        <ComboBox v-for="filter in filters" :key="filter.param" :model-value="filter.value" :options="filter.options"
            :placeholder="filter.placeholder" class="sm:flex-1 sm:min-w-36 sm:max-w-60"
            @update:model-value="value => pick(filter, value)" />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import { useQueryParams } from '@/Composables/useQueryParams'

/**
 * The filters above the table of upcoming machines. Each one offers only the values the
 * window actually contains. A filter in use starts its list with the way back to
 * everything ("Alle klanten"), and picking the chosen value again does the same; an empty
 * box filters nothing.
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

const CLEAR = '__clear__'

const { visit } = useQueryParams()

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
].map((filter) => {
    const emptied = filter.emptied ?? null
    const value = props.query[filter.param] === emptied ? null : props.query[filter.param]

    return {
        ...filter,
        emptied,
        value,
        options: value === null ? filter.options : [{ id: CLEAR, name: filter.everything }, ...filter.options],
    }
}))

function pick(filter, value) {
    visit({ [filter.param]: value === null || value === CLEAR ? filter.emptied : value })
}
</script>
