<template>
    <Listbox as="div" :model-value="listboxValue" @update:model-value="onListboxUpdate" v-slot="{ open }">
        <ListboxLabel class="sr-only">
            <slot name="sr-label">Selecteer optie</slot>
        </ListboxLabel>
        <div class="relative w-full" ref="wrapperRef">
            <div class="inline-flex divide-x rounded-md outline-hidden w-full">
                <div
                    class="inline-flex flex-grow items-center gap-x-1.5 rounded-l-md bg-white px-3 py-3 text-lavoro-dark dark:bg-lavoro-dark dark:text-white text-sm font-semibold border-0 ring-1 ring-gray-200 border-r-1 border-r-gray-100">
                    <component :is="icon" v-if="icon"
                        :class="['ml-0.5 size-5 mr-1 shrink-0', spin ? 'animate-spin' : '']" aria-hidden="true" />
                    <div class="relative">
                        <Transition :name="transitionName">
                            <p :key="currentLabel" class="text-sm font-medium whitespace-nowrap">
                                <span class="sm:hidden">{{ currentShortLabel }}</span>
                                <span class="hidden sm:inline">{{ currentLabel }}</span>
                            </p>
                        </Transition>
                    </div>
                </div>
                <ListboxButton
                    class="inline-flex items-center rounded-l-none rounded-r-md bg-white p-2  dark:bg-lavoro-dark dark:text-white ring-1 ring-gray-200">
                    <span class="sr-only">Selecteer optie</span>
                    <ChevronDownIcon class="size-5 text-lavoro-dark dark:text-white forced-colors:text-[Highlight]"
                        aria-hidden="true" />
                </ListboxButton>
            </div>

            <Teleport to="body">
                <transition leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100"
                    leave-to-class="opacity-0">
                    <div v-if="open" ref="floatingRef" :style="dropdownStyle">
                        <ListboxOptions static
                            class="w-72 divide-y divide-gray-200 overflow-hidden rounded-md bg-white shadow-lg outline-1 outline-black/5 dark:divide-white/10 dark:bg-gray-800 dark:shadow-none dark:-outline-offset-1 dark:outline-white/10">
                            <template v-if="options.length">
                                <ListboxOption v-for="option in options" :key="option.value" :value="option"
                                    as="template" v-slot="{ active, selected: isSelected }">
                                    <li
                                        :class="[active ? 'bg-indigo-600 text-white dark:bg-indigo-500' : 'text-gray-900 dark:text-white', 'cursor-default select-none p-4 text-sm']">
                                        <div class="flex flex-col">
                                            <div class="flex justify-between">
                                                <p :class="isSelected ? 'font-semibold' : 'font-normal'">{{ option.title
                                                }}</p>
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
                    </div>
                </transition>
            </Teleport>
        </div>
    </Listbox>
</template>

<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { Listbox, ListboxButton, ListboxLabel, ListboxOption, ListboxOptions } from '@headlessui/vue'
import { CheckIcon, ChevronDownIcon } from '@heroicons/vue/20/solid'
import { computePosition, autoUpdate, flip, offset } from '@floating-ui/dom'

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

const wrapperRef = ref(null)
const floatingRef = ref(null)
const dropdownStyle = ref({ position: 'fixed', zIndex: 9999 })
let stopAutoUpdate = null

async function updatePosition() {
    if (!wrapperRef.value || !floatingRef.value) return
    const { x, y } = await computePosition(wrapperRef.value, floatingRef.value, {
        placement: 'bottom-end',
        strategy: 'fixed',
        middleware: [offset(4), flip()],
    })
    dropdownStyle.value = { position: 'fixed', zIndex: 9999, top: `${y}px`, left: `${x}px` }
}

watch(floatingRef, (el) => {
    stopAutoUpdate?.()
    stopAutoUpdate = null
    if (el && wrapperRef.value) {
        stopAutoUpdate = autoUpdate(wrapperRef.value, el, updatePosition)
    }
})

onBeforeUnmount(() => stopAutoUpdate?.())

const listboxValue = computed(() => {
    if (props.options.length) {
        return props.options.find(o => o.value === model.value) ?? null
    }
    return model.value
})

const direction = ref('down')

const transitionName = computed(() => `select-label-${direction.value}`)

function onListboxUpdate(val) {
    if (props.options.length) {
        const oldIndex = props.options.findIndex(o => o.value === model.value)
        const newIndex = props.options.findIndex(o => o.value === val?.value)
        direction.value = newIndex > oldIndex ? 'down' : 'up'
        model.value = val?.value ?? null
    } else {
        model.value = val
    }
}

const currentLabel = computed(() => {
    const option = props.options.length ? props.options.find(o => o.value === model.value) : null
    return option?.title ?? props.label
})

const currentShortLabel = computed(() => {
    const option = props.options.length ? props.options.find(o => o.value === model.value) : null
    return option?.shortTitle ?? currentLabel.value
})
</script>

<style scoped>
.select-label-down-enter-active,
.select-label-down-leave-active,
.select-label-up-enter-active,
.select-label-up-leave-active {
    transition: opacity 0.15s ease, transform 0.35s ease;
}

.select-label-down-leave-active,
.select-label-up-leave-active {
    position: absolute;
    top: 0;
    left: 0;
}

/* Going down: new comes from below, old exits upward */
.select-label-down-enter-from {
    opacity: 0;
    transform: translateY(10px);
}

.select-label-down-leave-to {
    opacity: 0;
    transform: translateY(-10px);
}

/* Going up: new comes from above, old exits downward */
.select-label-up-enter-from {
    opacity: 0;
    transform: translateY(-10px);
}

.select-label-up-leave-to {
    opacity: 0;
    transform: translateY(10px);
}
</style>
