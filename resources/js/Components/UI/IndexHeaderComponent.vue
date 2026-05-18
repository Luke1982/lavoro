<template>
    <div class="flex justify-between items-center">
        <div class="flex flex-col">
            <span class="text-lg font-semibold">{{ title }}</span>
            <span class="text-xs">{{ subtitle }}</span>
        </div>
        <div class="flex justify-end gap-x-4">
            <div>
                <SearchComponent :url="searchUrl" :param="searchParam" :placeholder="searchPlaceholder"
                    :other-params="searchOtherParams" :local-storage-key="localStorageKey" :input-id="inputId" />
            </div>
            <button v-if="slots.filters" @click="filtersVisible = !filtersVisible"
                class="rounded-md border-gray-200 border-1 px-4 bg-white text-sm flex items-center gap-x-1 text-gray-800 cursor-pointer">
                <FilterIcon class="h-5 w-5 text-gray-500" />Filters
            </button>
            <button v-if="addLabel" @click="$emit('add')"
                class="cursor-pointer inline-flex items-center px-3 py-2 bg-lavoro-blue rounded-md text-white text-xs">
                <PlusIcon class="h-5 w-5 mr-2" />
                {{ addLabel }}
            </button>
        </div>
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
    addLabel: { type: String, default: '' },
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
})

const slots = useSlots();
const filtersVisible = ref(false)

defineEmits(['add'])
</script>
