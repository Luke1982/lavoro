<template>
    <!-- Header box -->
    <div class="p-4 bg-white rounded-md mb-3">
        <IndexHeaderComponent
            title="Keurpuntgroepen"
            subtitle="Overzicht van alle keurpuntgroepen"
            v-model="searchTerm"
            search-url="/servicecheckgroups"
            search-label="Zoek binnen groepen"
            search-placeholder="bijv. 'Inspectie'"
            :search-other-params="{ onlyType: productTypeToShow }"
            :paginator="groups"
            :pagination-params="{ search: searchTerm, onlyType: productTypeToShow }"
            add-label="Voeg groep toe"
            @add="() => groupFormRef?.show()"
        >
            <template #right>
                <div class="flex-grow mt-1">
                    <label class="block text-xs font-medium">Filter op type</label>
                    <ComboBox :options="productTypesForComboBox" v-model="productTypeToShow"
                        placeholder="Selecteer producttype" class="w-full mt-2" />
                </div>
                <XCircleIcon class="h-8 w-8 text-gray-400 cursor-pointer ml-2 mb-1"
                    @click="resetFilter"
                    v-tooltip="'Reset filter op producttype'" />
            </template>
        </IndexHeaderComponent>
    </div>

    <!-- Form box -->
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="groupFormRef" external-trigger action="/servicecheckgroups"
            :fields="groupFields" add-button-label="Voeg groep toe" submit-label="Opslaan"
            @created="onCreateSuccess" />
    </div>

    <!-- Content box -->
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <EditableGridComponent
            :headers="headers"
            :items="internalGroups"
            urlBase=""
            :hasDetailPages="false"
            @update="onCellUpdate"
        />
        <PaginationComponent v-if="internalGroups.length" :paginator="groups" :params="{ search: searchTerm, onlyType: productTypeToShow }"
            class="border-t border-gray-200 pt-2" />
        <p v-else class="text-center text-gray-500 p-4">Geen groepen gevonden.</p>
    </BoxComponent>
</template>

<script setup>
import { XCircleIcon } from '@heroicons/vue/24/outline'
import { router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue'

const { groups, productTypes, search } = defineProps({
    groups: { type: Object, required: true },
    productTypes: { type: Array, default: () => [] },
    search: { type: String, default: '' },
})

const searchTerm = ref(search)
const internalGroups = ref(groups.data)
const groupFormRef = ref(null)

const productTypesForComboBox = ref([{ id: '0', name: 'Selecteer type' }, ...productTypes])

const typeFromURL = typeof window !== 'undefined'
    ? new URLSearchParams(window.location.search).get('onlyType')
    : null
const productTypeToShow = ref(typeFromURL ? Number(typeFromURL) : null)

const groupFields = [
    { key: 'product_type_id', label: 'Producttype', type: 'combobox', options: productTypes, initialId: productTypes[0]?.id },
    { key: 'name', label: 'Naam', type: 'text' },
]

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 'w-1/3' },
    { key: 'product_type_id', label: 'Producttype', fieldtype: 'combobox', width: 'w-1/3', combovalues: productTypes },
    { key: 'order', label: 'Volgorde', fieldtype: 'number', width: 'w-24' },
]

function resetFilter() {
    productTypeToShow.value = null
    router.get('/servicecheckgroups', { search: searchTerm.value }, { preserveScroll: true })
}

function onCreateSuccess(newGroup) {
    if (!newGroup) return
    internalGroups.value.push({ ...newGroup })
    internalGroups.value.sort((a, b) => a.order - b.order)
}

// Optional: add delete column to headers if removal is required later

function onCellUpdate({ item }) {
    useForm({ ...item }).put(`/servicecheckgroups/${item.id}`, {
        preserveScroll: true,
    })
}
</script>