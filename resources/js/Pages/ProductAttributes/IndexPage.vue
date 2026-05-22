<template>
    <IndexHeaderComponent title="Productkenmerken"
        subtitle="Overzicht van alle kenmerken die aan producten kunnen worden gekoppeld"
        search-url="/productattributes" search-label="Zoek binnen kenmerken" search-placeholder="bijv. 'Kleur'"
        :paginator="attributes" add-label="Voeg kenmerk toe" @add="() => formRef?.show()" />

    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="formRef" external-trigger action="/productattributes" :fields="fields"
            add-button-label="Voeg kenmerk toe" submit-label="Opslaan" />
    </div>

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <EditableGridComponent :headers="headers" :items="attributes.data" url-base="productattributes"
            :has-detail-pages="true" />
        <PaginationComponent v-if="attributes.data.length" :paginator="attributes"
            class="border-t border-gray-200 dark:border-slate-700 pt-2" />
        <p v-else class="text-center text-gray-500 dark:text-slate-400 p-4">Geen kenmerken gevonden.</p>
    </BoxComponent>
</template>

<script setup>
import { ref } from 'vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue'
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'

defineProps({
    attributes: { type: Object, required: true },
    search: { type: String, default: '' },
})

const formRef = ref(null)

const fields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 70 },
]
</script>
