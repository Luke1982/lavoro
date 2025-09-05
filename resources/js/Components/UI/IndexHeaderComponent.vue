<template>
    <div class="mb-4">
        <div class="sm:flex justify-between items-center flex-wrap mb-4">
            <div>
                <h1 class="text-base font-semibold">{{ title }}</h1>
                <p v-if="subtitle" class="text-sm text-gray-700">{{ subtitle }}</p>
            </div>
            <button v-if="addLabel" @click="$emit('add')"
                class="cursor-pointer inline-flex items-center px-3 py-2 border border-green-900 text-green-900 bg-green-100 rounded-md text-sm">
                <PlusCircleIcon class="h-5 w-5 mr-1" />
                {{ addLabel }}
            </button>
        </div>
        
    <div v-if="showSearch" class="flex flex-wrap items-start">
            <div class="w-full md:w-2/3">
        <SearchComponent :url="searchUrl" :param="searchParam" :label="searchLabel"
            :placeholder="searchPlaceholder" :other-params="searchOtherParams"
            :local-storage-key="localStorageKey" :input-id="inputId" />
            </div>
            <div class="w-full md:w-1/3 md:pl-4 mt-4 md:mt-0 flex items-start">
                <slot name="right" />
            </div>
        </div>
        <PaginationComponent v-if="paginator" :paginator="paginator" :params="paginationParams"
            class="border-b border-gray-200 pb-2 mt-2" />
    </div>
    <slot />
</template>

<script setup>
import { PlusCircleIcon } from '@heroicons/vue/24/outline'
import SearchComponent from '@/Components/UI/SearchComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'

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

defineEmits(['add'])
</script>
