<template>
    <div role="columnheader" :aria-sort="ariaSort">
        <button type="button" class="group inline-flex items-center gap-1 cursor-pointer"
            :class="active && 'text-lavoro-blue dark:text-sky-300'" @click="cycle">
            <slot />
            <component :is="icon" class="size-4 shrink-0"
                :class="!active && 'text-gray-300 group-hover:text-gray-500 dark:text-slate-600 dark:group-hover:text-slate-400'" />
        </button>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { ArrowDown, ArrowUp, ChevronsUpDown } from '@lucide/vue'

/**
 * A column title that sorts its list. Each click moves one step: ascending, descending,
 * and then back to the list's own order, so a sort is undone where it was made.
 */
const props = defineProps({
    column: { type: String, required: true },
    /** The column the list is sorted on now. */
    sort: { type: String, required: true },
    direction: { type: String, required: true },
})

const emit = defineEmits(['sort'])

const active = computed(() => props.sort === props.column)
const ascending = computed(() => props.direction === 'asc')
const icon = computed(() => {
    if (!active.value) {
        return ChevronsUpDown
    }
    return ascending.value ? ArrowUp : ArrowDown
})
const ariaSort = computed(() => {
    if (!active.value) {
        return 'none'
    }
    return ascending.value ? 'ascending' : 'descending'
})

function cycle() {
    if (!active.value) {
        emit('sort', { sort: props.column, direction: 'asc' })
    } else if (ascending.value) {
        emit('sort', { sort: props.column, direction: 'desc' })
    } else {
        emit('sort', { sort: null, direction: null })
    }
}
</script>
