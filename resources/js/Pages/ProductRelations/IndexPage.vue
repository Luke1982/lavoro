<template>
    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 border border-gray-200 dark:border-slate-800 shadow-sm">
        <IndexHeaderComponent
            title="Relatietypes"
            subtitle="Overzicht van alle productrelatietypes"
            search-url="/productrelations"
            search-label="Zoek binnen relatietypes"
            search-placeholder="bijv. 'Onderdeel'"
            :paginator="relations"
            add-label="Voeg relatietype toe"
            @add="() => formRef?.show()"
        />
    </div>

    <div class="mb-4" v-auto-animate>
        <CreateRecordForm
            ref="formRef"
            external-trigger
            action="/productrelations"
            :fields="fields"
            add-button-label="Voeg relatietype toe"
            submit-label="Opslaan"
        />
    </div>

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <EditableGridComponent
            :headers="headers"
            :items="relations.data"
            url-base="productrelations"
            :has-detail-pages="false"
            @update="onCellUpdate"
        />
        <PaginationComponent
            v-if="relations.data.length"
            :paginator="relations"
            class="border-t border-gray-200 dark:border-slate-700 pt-2"
        />
        <p v-else class="text-center text-gray-500 dark:text-slate-400 p-4">Geen relatietypes gevonden.</p>
    </BoxComponent>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import BoxComponent from '@/Components/BoxComponent.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'

defineProps({
    relations: { type: Object, required: true },
    search:    { type: String, default: '' },
})

const formRef = ref(null)

const fields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 70 },
]

function onCellUpdate({ item }) {
    router.patch(`/productrelations/${item.id}`, { name: item.name }, {
        preserveScroll: true,
        preserveState: true,
    })
}
</script>
