<template>
    <div>
        <ComboBox v-for="filter in shown" :key="filter.param" :model-value="filter.value" :options="filter.options"
            :placeholder="filter.placeholder" :class="itemClass"
            :has-external-searching="Boolean(filter.search)" :searching="Boolean(filter.searching)"
            @change="query => filter.search?.(query)"
            @update:model-value="value => pick(filter, value)" />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import { useQueryParams } from '@/Composables/useQueryParams'

/**
 * The filter boxes above a list that keeps its state in the URL. A filter in use starts
 * its list with the way back to everything ("Alle klanten"), and picking the chosen value
 * again does the same; an empty box filters nothing.
 *
 * A filter that is in use from the start, because the list opens on a narrower default
 * (the running projects, the machines nobody planned yet), says what "everything" is
 * called on the server with `emptied`: emptying it has to be said out loud, or the
 * server falls back to its default.
 *
 * Each filter: { param, placeholder, everything, options, emptied?, search?, searching? }.
 * `search` is called with what is typed, for a box that looks its options up instead of
 * holding them all.
 */
const props = defineProps({
    filters: { type: Array, required: true },
    /** The filters in effect, defaults included, by param. */
    query: { type: Object, required: true },
    itemClass: { type: String, default: '' },
})

const CLEAR = '__clear__'

const { visit } = useQueryParams()

const shown = computed(() => props.filters.map((filter) => {
    const emptied = filter.emptied ?? null
    const value = props.query[filter.param] === emptied ? null : (props.query[filter.param] ?? null)

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
