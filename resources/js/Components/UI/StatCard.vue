<template>
    <div class="w-full">
        <p class="text-sm font-medium text-gray-900 dark:text-gray-500 leading-tight">{{ label }}</p>
        <div class="mt-0.5 flex items-start justify-between gap-4">
            <div class="flex items-baseline gap-2">
                <p :class="['text-3xl font-semibold tracking-tight leading-none', valueColour]">{{ formattedValue }}</p>
                <span class="text-sm text-gray-500 dark:text-gray-400">gem. {{ baselineFormatted }}</span>
            </div>
            <span :class="badgeClasses">
                <component :is="icon" class="h-4 w-4 mr-1" />
                {{ deltaAbs }}
            </span>
        </div>
    </div>
</template>
<script setup>
import { computed } from 'vue'
import { ArrowTrendingUpIcon, ArrowTrendingDownIcon } from '@heroicons/vue/24/solid'
const props = defineProps({
    label: String,
    value: Number,
    delta: Number,
    baseline: Number,
    type: String
})
const nf = new Intl.NumberFormat('nl-NL')
// Positive if more closed than average OR fewer open/pending than average
const isPositive = computed(() => {
    if (props.type === 'closed') return props.delta >= 0
    return props.delta <= 0
})
const icon = computed(() => props.delta >= 0 ? ArrowTrendingUpIcon : ArrowTrendingDownIcon)
const deltaAbs = computed(() => Math.abs(props.delta).toFixed(2) + '%')
const valueColour = computed(() => 'text-indigo-600')
const badgeClasses = computed(() => isPositive.value ? 'inline-flex items-center rounded-full bg-green-100 text-green-800 px-2 py-0.5 text-xs font-medium' : 'inline-flex items-center rounded-full bg-red-100 text-red-800 px-2 py-0.5 text-xs font-medium')
const formattedValue = computed(() => nf.format(props.value))
const baselineFormatted = computed(() => nf.format(props.baseline))
</script>
