<template>
    <Listbox as="div" :model-value="model" @update:model-value="onSelect">
        <div class="relative">
            <ListboxButton
                class="w-full flex items-center gap-3 rounded-lg bg-white dark:bg-lavoro-darkdark:ring-white/10 px-3 py-2.5 text-left focus:outline-none">
                <template v-if="model">
                    <img v-if="model.thumbnail_url" :src="model.thumbnail_url" :alt="model.name"
                        class="size-12 rounded-md object-contain bg-gray-50 shrink-0" />
                    <div v-else class="size-12 rounded-md bg-gray-100 dark:bg-slate-700 shrink-0" />
                </template>
                <div v-else class="size-12 rounded-md bg-gray-100 dark:bg-slate-700 shrink-0" />

                <div class="relative flex-1 min-w-0 overflow-hidden">
                    <Transition :name="transitionName">
                        <div v-if="model" :key="model.id" class="min-w-0">
                            <p class="text-sm font-semibold text-lavoro-dark dark:text-white truncate">{{ model.name }}
                            </p>
                            <p class="text-xs text-gray-400 dark:text-slate-400 truncate">
                                {{ [model.category, model.article_number].filter(Boolean).join(' • ') }}
                            </p>
                        </div>
                        <div v-else key="empty" class="min-w-0">
                            <p class="text-sm text-gray-400 dark:text-slate-500">{{ placeholder }}</p>
                        </div>
                    </Transition>
                </div>

                <ChevronDownIcon class="size-5 text-gray-400 dark:text-slate-400 shrink-0" aria-hidden="true" />
            </ListboxButton>

            <transition leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100"
                leave-to-class="opacity-0">
                <ListboxOptions
                    class="absolute z-10 mt-1 w-full overflow-auto rounded-md bg-white dark:bg-gray-800 shadow-lg outline-1 outline-black/5 dark:outline-white/10 max-h-72 focus:outline-none">
                    <ListboxOption v-for="asset in assets" :key="asset.id" :value="asset" as="template"
                        v-slot="{ active, selected: isSelected }">
                        <li :class="[
                            active ? 'bg-indigo-600' : '',
                            'flex items-center gap-3 px-3 py-2.5 cursor-default select-none',
                        ]">
                            <img v-if="asset.thumbnail_url" :src="asset.thumbnail_url" :alt="asset.name"
                                class="size-12 rounded-md object-contain bg-gray-50 shrink-0" />
                            <div v-else class="size-12 rounded-md bg-gray-100 dark:bg-slate-700 shrink-0" />
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <p :class="[
                                        active ? 'text-white' : 'text-lavoro-dark dark:text-white',
                                        isSelected ? 'font-semibold' : 'font-normal',
                                        'text-sm truncate',
                                    ]">{{ asset.name }}</p>
                                    <span v-if="asset.is_bundle && !asset.serial_number"
                                        :class="['shrink-0 text-xs font-medium px-1.5 py-0.5 rounded-full', active ? 'bg-white/20 text-white' : 'bg-lavoro-blue/10 text-lavoro-blue']">
                                        Bundle
                                    </span>
                                </div>
                                <p :class="[
                                    active ? 'text-indigo-200' : 'text-gray-400 dark:text-slate-400',
                                    'text-xs truncate',
                                ]">
                                    {{ [asset.category, asset.article_number].filter(Boolean).join(' • ') }}
                                </p>
                                <p :class="[
                                    active ? 'text-indigo-200' : 'text-gray-400 dark:text-slate-400',
                                    'text-xs truncate',
                                ]">
                                    {{ [asset.serial_number, asset.next_service_date ? 'Keuring: ' +
                                        asset.next_service_date :
                                        null].filter(Boolean).join(' • ') }}
                                </p>
                            </div>
                            <CheckIcon v-if="isSelected"
                                :class="[active ? 'text-white' : 'text-indigo-600 dark:text-indigo-400', 'size-5 shrink-0']"
                                aria-hidden="true" />
                        </li>
                    </ListboxOption>
                </ListboxOptions>
            </transition>
        </div>
    </Listbox>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/vue'
import { CheckIcon, ChevronDownIcon } from '@heroicons/vue/20/solid'

const props = defineProps({
    assets: {
        type: Array,
        default: () => [],
    },
    placeholder: {
        type: String,
        default: 'Selecteer asset',
    },
})

const emit = defineEmits(['select'])

const model = defineModel()

const direction = ref('down')
const transitionName = computed(() => `asset-label-${direction.value}`)

function onSelect(asset) {
    const oldIndex = props.assets.findIndex(a => a.id === model.value?.id)
    const newIndex = props.assets.findIndex(a => a.id === asset?.id)

    if (model.value?.id === asset?.id) {
        direction.value = 'down'
        model.value = null
        emit('select', null)
    } else {
        direction.value = newIndex > oldIndex ? 'down' : 'up'
        model.value = asset
        emit('select', asset)
    }
}
</script>

<style scoped>
.asset-label-down-enter-active,
.asset-label-down-leave-active,
.asset-label-up-enter-active,
.asset-label-up-leave-active {
    transition: opacity 0.15s ease, transform 0.35s ease;
}

.asset-label-down-leave-active,
.asset-label-up-leave-active {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
}

.asset-label-down-enter-from {
    opacity: 0;
    transform: translateY(10px);
}

.asset-label-down-leave-to {
    opacity: 0;
    transform: translateY(-10px);
}

.asset-label-up-enter-from {
    opacity: 0;
    transform: translateY(-10px);
}

.asset-label-up-leave-to {
    opacity: 0;
    transform: translateY(10px);
}
</style>
