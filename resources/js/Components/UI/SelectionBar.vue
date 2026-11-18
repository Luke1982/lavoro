<template>
    <BottomActionBar :show="count > 0">
        <span class="text-base font-bold">{{ count }} {{ noun }} geselecteerd</span>
        <button type="button" @click="$emit('clear')"
            class="text-xs text-gray-500 dark:text-slate-400 underline hover:text-gray-700 dark:hover:text-slate-300">
            Deselecteer alles
        </button>
        <template #actions>
            <slot />
        </template>
    </BottomActionBar>
</template>

<script setup>
import BottomActionBar from '@/Components/UI/BottomActionBar.vue'

/** The bar that comes up when rows in a list are ticked, with what can be done with all of them in its slot. */
defineProps({
    count: { type: Number, required: true },
    /** What is selected, in the plural: "storingen". */
    noun: { type: String, required: true },
})

defineEmits(['clear'])
</script>
