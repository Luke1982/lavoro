<template>
    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 border dark:border-slate-800">
        <IndexHeaderComponent title="Producttypen" subtitle="Hieronder een lijst van alle producttypen"
            search-url="/producttypes" search-label="Zoek binnen producttypen"
            search-placeholder="bijv. 'Viergastester'" add-label="Voeg producttype toe" :paginator="productTypes"
            @add="() => typeFormRef?.show()" />
    </div>
    <div class="mt-0 mb-4" v-auto-animate>
        <CreateRecordForm ref="typeFormRef" external-trigger action="/producttypes" :fields="typeFields"
            add-button-label="Voeg producttype toe" submit-label="Toevoegen" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <EditableGridComponent :headers="headers" :items="innerTypes" @update="onCellUpdate" urlBase="producttypes" />
        <PaginationComponent v-if="innerTypes.length" :paginator="productTypes" class="border-t border-gray-200 pt-2" />
        <div v-else class="text-center text-gray-500 p-4">
            <p>Geen producttypen gevonden.</p>
            <p>Probeer een andere zoekterm of voeg een nieuw producttype toe.</p>
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

const typeFormRef = ref(null)

const props = defineProps({
    productTypes: {
        type: Object,
        required: true,
    },
    search: {
        type: String,
        default: '',
    },
});

const typeFields = [
    { key: 'name', label: 'Naam', type: 'text' },
    { key: 'typical_certificate_days', label: 'Keuringsduur (dagen)', type: 'number', default: 0 },
]

const innerTypes = computed(() => props.productTypes?.data || [])

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 70 },
    { key: 'typical_certificate_days', label: 'Keuringsduur (dagen)', fieldtype: 'number' },
]

const form = useForm({ name: null, typical_certificate_days: null });
function onCellUpdate({ item }) {
    form.transform(() => ({ ...item }))
        .put(`/producttypes/${item.id}`, { preserveScroll: true });
}
</script>
