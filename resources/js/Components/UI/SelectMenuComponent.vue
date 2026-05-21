<template>
    <Listbox as="div" :model-value="listboxValue" @update:model-value="onListboxUpdate">
        <ListboxLabel class="sr-only">
            <slot name="sr-label">Selecteer optie</slot>
        </ListboxLabel>
        <div class="relative">
            <div class="inline-flex divide-x rounded-md outline-hidden">
                <div
                    class="inline-flex items-center gap-x-1.5 rounded-l-md bg-white px-3 py-3 text-lavoro-dark dark:bg-lavoro-dark dark:text-white text-sm font-semibold border-0 ring-1 ring-gray-200 border-r-1 border-r-gray-100">
                    <component :is="icon" v-if="icon"
                        :class="['ml-0.5 size-5 mr-1 shrink-0', spin ? 'animate-spin' : '']" aria-hidden="true" />
                    <Transition enter-active-class="transition duration-150 ease-out"
                        enter-from-class="opacity-0 translate-y-1" enter-to-class="opacity-100 translate-y-0"
                        leave-active-class="transition duration-100 ease-in"
                        leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 -translate-y-1"
                        mode="out-in">
                        <p class="sm:hidden text-sm font-medium whitespace-nowrap" :key="currentLabel.short">{{
                            currentLabel.short }}</p>
                    </Transition>
                    <Transition enter-active-class="transition duration-150 ease-out"
                        enter-from-class="opacity-0 translate-y-1" enter-to-class="opacity-100 translate-y-0"
                        leave-active-class="transition duration-100 ease-in"
                        leave-from-class="opacity-100 translate-y-0" leave-to-class="opacity-0 -translate-y-1"
                        mode="out-in">
                        <p :key="currentLabel.long" class="hidden sm:inline text-sm font-medium whitespace-nowrap">{{
                            currentLabel.long }}</p>
                    </Transition>
                </div>
                <ListboxButton
                    class="inline-flex items-center rounded-l-none rounded-r-md bg-white p-2  dark:bg-lavoro-dark dark:text-white ring-1 ring-gray-200">
                    <span class="sr-only">Selecteer optie</span>
                    <ChevronDownIcon class="size-5 text-lavoro-dark dark:text-white forced-colors:text-[Highlight]"
                        aria-hidden="true" />
                </ListboxButton>
            </div>

            <transition leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100"
                leave-to-class="opacity-0">
                <ListboxOptions
                    class="absolute right-0 z-10 mt-2 w-72 origin-top-right divide-y divide-gray-200 overflow-hidden rounded-md bg-white shadow-lg outline-1 outline-black/5 dark:divide-white/10 dark:bg-gray-800 dark:shadow-none dark:-outline-offset-1 dark:outline-white/10">
                    <template v-if="options.length">
                        <ListboxOption v-for="option in options" :key="option.value" :value="option" as="template"
                            v-slot="{ active, selected: isSelected }">
                            <li
                                :class="[active ? 'bg-indigo-600 text-white dark:bg-indigo-500' : 'text-gray-900 dark:text-white', 'cursor-default select-none p-4 text-sm']">
                                <div class="flex flex-col">
                                    <div class="flex justify-between">
                                        <p :class="isSelected ? 'font-semibold' : 'font-normal'">{{ option.title }}</p>
                                        <span v-if="isSelected"
                                            :class="active ? 'text-white' : 'text-indigo-600 dark:text-indigo-400'">
                                            <CheckIcon class="size-5" aria-hidden="true" />
                                        </span>
                                    </div>
                                    <p v-if="option.description"
                                        :class="[active ? 'text-indigo-200 dark:text-indigo-100' : 'text-gray-500 dark:text-gray-400', 'mt-2']">
                                        {{ option.description }}
                                    </p>
                                </div>
                            </li>
                        </ListboxOption>
                    </template>
                    <slot v-else name="options" />
                </ListboxOptions>
            </transition>
        </div>
    </Listbox>
</template>

<script setup>
import { computed } from 'vue'
import { Listbox, ListboxButton, ListboxLabel, ListboxOption, ListboxOptions } from '@headlessui/vue'
import { CheckIcon, ChevronDownIcon } from '@heroicons/vue/20/solid'

const props = defineProps({
    options: {
        type: Array,
        default: () => [],
    },
    icon: {
        type: [Object, Function],
        default: null,
    },
    label: {
        type: String,
        default: '',
    },
    spin: {
        type: Boolean,
        default: false,
    },
})

const model = defineModel()

const listboxValue = computed(() => {
    if (props.options.length) {
        return props.options.find(o => o.value === model.value) ?? null
    }
    return model.value
})

function onListboxUpdate(val) {
    if (props.options.length) {
        model.value = val?.value ?? null
    } else {
        model.value = val
    }
}

const currentLabel = computed(() => {
    const option = props.options.length ? props.options.find(o => o.value === model.value) : null
    const long = option?.title ?? props.label
    return { long, short: option?.shortTitle ?? long }
})
</script>
