<template>
    <div class="flex w-full flex-col items-center md:w-auto">
        <MacroDropZone :lane="steps" :index="0" />
        <template v-for="(step, index) in steps" :key="step.id">
            <MacroBlock :step="step" :is-first="index === 0" :is-last="index === steps.length - 1" />
            <div v-if="step.type === 'question'" class="flex w-full flex-col gap-3 pt-3 md:w-auto md:flex-row md:items-start md:gap-6">
                <div v-for="branch in BRANCHES" :key="branch.key"
                    class="flex flex-col items-center rounded-lavoro-sm border border-dashed border-slate-300 px-3 pb-3 pt-2 md:min-w-60 dark:border-slate-600">
                    <span :class="['rounded-full px-2.5 py-0.5 text-xs font-bold', branch.tone]">{{ branch.label }}</span>
                    <MacroLane :steps="step[branch.key]" />
                </div>
            </div>
            <MacroDropZone :lane="steps" :index="index + 1" />
        </template>
    </div>
</template>

<script setup>
import MacroBlock from '@/Components/Macros/MacroBlock.vue'
import MacroDropZone from '@/Components/Macros/MacroDropZone.vue'

/**
 * A lane of the macro, top to bottom, with a place to drop a block between every
 * two. A question splits into a Ja and a Nee lane that come together below it;
 * side by side where there is room, the one under the other on a phone.
 */
defineProps({
    steps: { type: Array, required: true },
})

const BRANCHES = [
    { key: 'yes', label: 'Ja', tone: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' },
    { key: 'no', label: 'Nee', tone: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
]
</script>
