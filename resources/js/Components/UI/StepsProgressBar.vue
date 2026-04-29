<template>
    <nav aria-label="Progress">
        <ol role="list"
            class="divide-y divide-gray-300 rounded-md border border-gray-300 md:flex md:divide-y-0 dark:divide-white/15 dark:border-white/15">
            <li v-for="(step, stepIdx) in steps" :key="step.id" class="relative md:flex md:flex-1">
                <button v-if="stepStatus(stepIdx) === 'complete'" @click="$emit('update:modelValue', step.id)"
                    class="group flex w-full items-center cursor-pointer">
                    <span class="flex items-center px-3 py-1.5 text-xs font-medium">
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 group-hover:bg-indigo-800 dark:bg-indigo-500 dark:group-hover:bg-indigo-400">
                            <CheckIcon class="size-3.5 text-white" aria-hidden="true" />
                        </span>
                        <span class="ml-2 text-xs font-medium text-gray-900 dark:text-white">{{ step.name }}</span>
                    </span>
                </button>
                <button v-else-if="stepStatus(stepIdx) === 'current'"
                    class="flex items-center px-3 py-1.5 text-xs font-medium cursor-default" aria-current="step">
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-indigo-600 dark:border-indigo-400">
                        <span class="text-[10px] text-indigo-600 dark:text-indigo-400">{{ String(stepIdx +
                            1).padStart(2, '0') }}</span>
                    </span>
                    <span class="ml-2 text-xs font-medium text-indigo-600 dark:text-indigo-400">{{ step.name }}</span>
                </button>
                <button v-else @click="$emit('update:modelValue', step.id)"
                    class="group flex items-center cursor-pointer">
                    <span class="flex items-center px-3 py-1.5 text-xs font-medium">
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-gray-300 group-hover:border-gray-400 dark:border-white/15 dark:group-hover:border-white/25">
                            <span
                                class="text-[10px] text-gray-500 group-hover:text-gray-900 dark:text-gray-400 dark:group-hover:text-white">{{
                                    String(stepIdx + 1).padStart(2, '0') }}</span>
                        </span>
                        <span
                            class="ml-2 text-xs font-medium text-gray-500 group-hover:text-gray-900 dark:text-gray-400 dark:group-hover:text-white">{{
                            step.name }}</span>
                    </span>
                </button>
                <template v-if="stepIdx !== steps.length - 1">
                    <div class="absolute top-0 right-0 hidden h-full w-5 md:block" aria-hidden="true">
                        <svg class="size-full text-gray-300 dark:text-white/15" viewBox="0 0 22 80" fill="none"
                            preserveAspectRatio="none">
                            <path d="M0 -2L20 40L0 82" vector-effect="non-scaling-stroke" stroke="currentcolor"
                                stroke-linejoin="round" />
                        </svg>
                    </div>
                </template>
            </li>
        </ol>
    </nav>
</template>

<script setup>
import { computed } from 'vue'
import { CheckIcon } from '@heroicons/vue/24/solid'

const props = defineProps({
    steps: { type: Array, required: true },
    modelValue: { type: [String, Number], default: null },
})

defineEmits(['update:modelValue'])

const currentIndex = computed(() => props.steps.findIndex(s => s.id === props.modelValue))

function stepStatus(idx) {
    if (currentIndex.value === -1) return 'upcoming'
    if (idx < currentIndex.value) return 'complete'
    if (idx === currentIndex.value) return 'current'
    return 'upcoming'
}
</script>
