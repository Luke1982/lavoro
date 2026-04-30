<template>
    <div>
        <div class="md:hidden">
            <Listbox :modelValue="selectedStep" @update:modelValue="onSelect">
                <ListboxLabel class="sr-only">Status wijzigen</ListboxLabel>
                <div class="relative">
                    <ListboxButton
                        class="w-full inline-flex items-center justify-between gap-2 rounded-md bg-indigo-600 px-3 py-2 text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-indigo-400 dark:bg-indigo-500 dark:hover:bg-indigo-400">
                        <span class="inline-flex items-center gap-1.5 min-w-0">
                            <CheckIcon class="size-5 shrink-0" aria-hidden="true" />
                            <span class="text-sm font-semibold truncate">{{ selectedStep?.name ?? 'Selecteer' }}</span>
                        </span>
                        <ChevronDownIcon class="size-5 shrink-0" aria-hidden="true" />
                    </ListboxButton>
                    <transition leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100"
                        leave-to-class="opacity-0">
                        <ListboxOptions
                            class="absolute left-0 right-0 z-10 mt-2 divide-y divide-gray-200 overflow-hidden rounded-md bg-white shadow-lg outline-1 outline-black/5 dark:divide-white/10 dark:bg-gray-800 dark:shadow-none dark:-outline-offset-1 dark:outline-white/10">
                            <ListboxOption as="template" v-for="option in steps" :key="option.id" :value="option"
                                v-slot="{ active, selected }">
                                <li
                                    :class="[active ? 'bg-indigo-600 text-white dark:bg-indigo-500' : 'text-gray-900 dark:text-white', 'cursor-pointer p-3 text-sm select-none']">
                                    <div class="flex items-center justify-between">
                                        <p :class="selected ? 'font-semibold' : 'font-normal'">{{ option.name }}</p>
                                        <span v-if="selected"
                                            :class="active ? 'text-white' : 'text-indigo-600 dark:text-indigo-400'">
                                            <CheckIcon class="size-5" aria-hidden="true" />
                                        </span>
                                    </div>
                                    <p v-if="option.description"
                                        :class="[active ? 'text-indigo-200 dark:text-indigo-100' : 'text-gray-500 dark:text-gray-400', 'mt-1.5 text-xs']">
                                        {{ option.description }}
                                    </p>
                                </li>
                            </ListboxOption>
                        </ListboxOptions>
                    </transition>
                </div>
            </Listbox>
        </div>

        <nav aria-label="Progress" class="hidden md:block">
            <ol role="list"
                class="rounded-md border border-gray-300 flex dark:border-white/15">
                <li v-for="(step, stepIdx) in steps" :key="step.id" class="relative flex flex-1 py-1">
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
                        <span class="ml-2 text-xs font-medium text-indigo-600 dark:text-indigo-400">{{ step.name
                            }}</span>
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
                        <div class="absolute top-0 right-0 h-full w-5" aria-hidden="true">
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
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Listbox, ListboxButton, ListboxLabel, ListboxOption, ListboxOptions } from '@headlessui/vue'
import { CheckIcon } from '@heroicons/vue/24/solid'
import { ChevronDownIcon } from '@heroicons/vue/20/solid'

const props = defineProps({
    steps: { type: Array, required: true },
    modelValue: { type: [String, Number], default: null },
})

const emit = defineEmits(['update:modelValue'])

const currentIndex = computed(() => props.steps.findIndex(s => s.id === props.modelValue))

const selectedStep = computed(() => props.steps[currentIndex.value] ?? null)

function stepStatus(idx) {
    if (currentIndex.value === -1) return 'upcoming'
    if (idx < currentIndex.value) return 'complete'
    if (idx === currentIndex.value) return 'current'
    return 'upcoming'
}

function onSelect(step) {
    emit('update:modelValue', step.id)
}
</script>
