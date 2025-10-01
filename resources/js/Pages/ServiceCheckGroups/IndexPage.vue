<template>
    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 border dark:border-slate-800">
        <IndexHeaderComponent title="Keurpuntgroepen" subtitle="Overzicht van alle keurpuntgroepen"
            search-url="/servicecheckgroups" search-label="Zoek binnen groepen" search-placeholder="bijv. 'Inspectie'"
            :search-other-params="{ onlyType: productTypeToShow }" :paginator="groups" add-label="Voeg groep toe"
            @add="() => groupFormRef?.show()">
            <template #right>
                <div class="flex-grow mt-1">
                    <label class="block text-xs font-medium text-gray-900 dark:text-gray-300">Filter op type</label>
                    <ComboBox :options="productTypesForComboBox" v-model="productTypeToShow"
                        placeholder="Selecteer producttype" class="w-full mt-2" />
                </div>
                <XCircleIcon
                    class="h-8 w-8 text-gray-400 dark:text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 cursor-pointer ml-2 mb-1"
                    @click="resetFilter" v-tooltip="'Reset filter op producttype'" />
            </template>
        </IndexHeaderComponent>
    </div>

    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="groupFormRef" external-trigger action="/servicecheckgroups" :fields="groupFields"
            add-button-label="Voeg groep toe" submit-label="Opslaan" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <EditableGridComponent :headers="headers" :items="internalGroups" urlBase="servicecheckgroups"
            :hasDetailPages="false" @update="onCellUpdate" />
        <PaginationComponent v-if="groups.data.length" :paginator="groups"
            class="border-t border-gray-200 dark:border-slate-700 pt-2" />
        <p v-else class="text-center text-gray-500 dark:text-slate-400 p-4">Geen groepen gevonden.</p>
    </BoxComponent>
</template>

<script setup>
import { XCircleIcon } from '@heroicons/vue/24/outline'
import { useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue'

const { groups, productTypes } = defineProps({
    groups: { type: Object, required: true },
    productTypes: { type: Array, default: () => [] },
    search: { type: String, default: '' },
})

const groupFormRef = ref(null)

const productTypesForComboBox = ref([{ id: '0', name: 'Selecteer type' }, ...productTypes])

const typeFromURL = typeof window !== 'undefined'
    ? new URLSearchParams(window.location.search).get('onlyType')
    : null
const productTypeToShow = ref(typeFromURL ? Number(typeFromURL) : null)

const groupFields = [
    { key: 'product_type_ids', label: 'Producttypes', type: 'combobox', options: productTypes, multiple: true, initialIds: productTypes.length ? [productTypes[0].id] : [] },
    { key: 'name', label: 'Naam', type: 'text' },
]

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 33 },
    { key: 'product_type_ids', label: 'Producttypes', fieldtype: 'combobox', width: 33, combovalues: productTypes, multiple: true, comboLabel: false },
    { key: 'order', label: 'Volgorde', fieldtype: 'number', width: 24 },
]

// Map product_types to product_type_ids for editable grid initial selection
const internalGroups = computed(() => (groups.data || []).map(g => ({
    ...g,
    product_type_ids: (g.product_types || []).map(pt => pt.id),
})))

function resetFilter() {
    productTypeToShow.value = null
}

function onCellUpdate({ item }) {
    const payload = { ...item }
    payload.product_type_ids = Array.isArray(item.product_type_ids)
        ? item.product_type_ids
        : (Array.isArray(item.product_types) ? item.product_types.map(pt => pt.id) : [])
    useForm(payload).put(`/servicecheckgroups/${item.id}`, {
        preserveScroll: true,
    })
}
</script>