<template>
    <button type="button" @click="$emit('click')" :class="[
        'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold transition-colors cursor-pointer hover:opacity-80',
        done >= total
            ? 'border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-400'
            : 'border-gray-200 bg-white text-gray-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300',
    ]">
        <ListChecksIcon class="w-3 h-3" />
        {{ done }}/{{ total }} checks
    </button>
</template>

<script setup>
import { ListChecks as ListChecksIcon } from '@lucide/vue'

/** How far a task's checks are, next to its machines; opens the task to tick them. */
defineProps({
    done: { type: Number, required: true },
    total: { type: Number, required: true },
})

defineEmits(['click'])
</script>
