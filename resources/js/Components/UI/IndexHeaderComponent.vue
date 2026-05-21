<template>
    <div class="flex justify-between items-center">
        <div class="flex flex-col">
            <span class="flex items-center gap-2 text-lg font-semibold">
                <component :is="icon" v-if="icon" class="size-5 shrink-0" aria-hidden="true" />
                {{ title }}
            </span>
            <span class="text-xs">{{ subtitle }}</span>
        </div>
        <div class="flex justify-end gap-x-2 sm:gap-x-4">
            <div class="hidden sm:block">
                <SearchComponent :url="searchUrl" :param="searchParam" :placeholder="searchPlaceholder"
                    :other-params="searchOtherParams" :local-storage-key="localStorageKey" :input-id="inputId" />
            </div>
            <button v-if="slots.filters" @click="filtersVisible = !filtersVisible"
                class="rounded-md border-gray-200 border-1 px-2 sm:px-4 bg-white text-sm flex items-center gap-x-1 text-gray-800 cursor-pointer relative">
                <FilterIcon class="h-5 w-5 text-gray-500" /><span class="hidden sm:inline">Filters</span>
                <div v-if="hasActiveFilters" class="absolute right-2 top-2 bg-green-500 rounded-full h-2 w-2"></div>
            </button>
            <slot name="actions" />
            <button v-if="addLabel && canAdd" @click="$emit('add')"
                class="cursor-pointer inline-flex items-center px-3 py-2 bg-lavoro-blue rounded-md text-white text-xs">
                <PlusIcon class="h-5 w-5 mr-0 sm:mr-2" />
                <span class="hidden sm:inline">{{ addLabel }}</span>
            </button>
        </div>
    </div>
    <div class="mt-2 block sm:hidden">
        <SearchComponent :url="searchUrl" :param="searchParam" :placeholder="searchPlaceholder"
            :other-params="searchOtherParams" :local-storage-key="localStorageKey" :input-id="inputId" />
    </div>
    <div v-auto-animate>
        <div v-if="slots.filters && filtersVisible" class="mt-4">
            <BoxComponent class="mt-4">
                <slot name="filters" />
            </BoxComponent>
        </div>
    </div>
    <div class="mb-4">
        <PaginationComponent v-if="paginator" :paginator="paginator" :params="paginationParams"
            class="border-b border-gray-200 pb-2 mt-4 dark:border-slate-700" />
    </div>
    <slot />
</template>

<script setup>
import SearchComponent from '@/Components/UI/SearchComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import { FilterIcon, PlusIcon } from '@lucide/vue'
import BoxComponent from '../BoxComponent.vue'
import { useSlots, ref } from 'vue'

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    icon: { type: [Object, Function], default: null },
    addLabel: { type: String, default: '' },
    canAdd: { type: Boolean, default: true },
    inAction: { type: Boolean, default: false },
    searchLabel: { type: String, default: '' },
    searchPlaceholder: { type: String, default: '' },
    showSearch: { type: Boolean, default: true },
    // SearchComponent passthrough props
    searchUrl: { type: String, default: '' },
    searchParam: { type: String, default: 'search' },
    searchOtherParams: { type: Object, default: () => ({}) },
    localStorageKey: { type: String, default: 'searchInitiated' },
    inputId: { type: String, default: 'searchInput' },
    // Pagination passthrough props
    paginator: { type: Object, default: null },
    paginationParams: { type: Object, default: () => ({}) },
    hasActiveFilters: { type: Boolean, default: false },
})

const slots = useSlots();
const filtersVisible = ref(false)

defineEmits(['add'])
</script>
