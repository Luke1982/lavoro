<template>
    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 border border-gray-200 dark:border-slate-800 shadow-sm">
        <IndexHeaderComponent title="Extra velden" subtitle="Beheer extra velden voor klanten, machines, etc."
            search-url="/customfields" search-label="Zoek binnen extra velden"
            search-placeholder="bijv. 'Contactpersoon'" add-label="Voeg veld toe"
            :paginator="customFields" @add="() => formRef?.show()" />
    </div>
    <div v-auto-animate class="mb-4">
        <CreateRecordForm ref="formRef" external-trigger action="/customfields" :fields="createFields"
            add-button-label="Voeg veld toe" submit-label="Toevoegen" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <EditableGridComponent :headers="headers" :items="innerFields" @update="onCellUpdate"
            urlBase="customfields" />
        <PaginationComponent v-if="innerFields.length > 0" :paginator="customFields"
            class="border-t border-gray-200 pt-2" />
        <div v-else class="text-center text-gray-500 p-4">
            <p>Geen extra velden gevonden.</p>
            <p>Probeer een andere zoekterm of voeg een nieuw veld toe.</p>
        </div>
    </BoxComponent>
</template>
<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import PaginationComponent from '@/Components/UI/PaginationComponent.vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import BoxComponent from '@/Components/BoxComponent.vue';
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue';

const formRef = ref(null)

const props = defineProps({
    customFields: {
        type: Object,
        required: true,
    },
    fieldTypes: {
        type: Array,
        required: true,
    },
    targetModels: {
        type: Array,
        required: true,
    },
    search: {
        type: String,
        default: '',
    },
});

const createFields = [
    { key: 'name', label: 'Naam', type: 'text' },
    {
        key: 'model_type',
        label: 'Model',
        type: 'combobox',
        options: props.targetModels,
    },
    {
        key: 'field_type',
        label: 'Type',
        type: 'combobox',
        options: props.fieldTypes,
        initialId: 'text',
    },
    { key: 'sort_order', label: 'Volgorde', type: 'number', default: 0 },
    { key: 'required', label: 'Verplicht', type: 'boolean', default: false },
]

const innerFields = computed(() => props.customFields?.data || [])

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 25 },
    {
        key: 'model_type',
        label: 'Model',
        fieldtype: 'combobox',
        width: 20,
        combovalues: props.targetModels,
    },
    {
        key: 'field_type',
        label: 'Type',
        fieldtype: 'combobox',
        width: 20,
        combovalues: props.fieldTypes,
    },
    { key: 'sort_order', label: 'Volgorde', fieldtype: 'number', width: 10 },
    { key: 'required', label: 'Verplicht', fieldtype: 'boolean', width: 10 },
];

const form = useForm({
    name: null,
    model_type: null,
    field_type: null,
    sort_order: null,
    required: null,
});

function onCellUpdate({ item }) {
    form.transform(() => ({ ...item }))
        .put(`/customfields/${item.id}`, { preserveScroll: true });
}
</script>
