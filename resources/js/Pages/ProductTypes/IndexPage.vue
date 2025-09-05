<template>
    <!-- Header box -->
    <div class="p-4 bg-white rounded-md mb-3">
        <IndexHeaderComponent title="Producttypen" subtitle="Hieronder een lijst van alle producttypen"
            v-model="searchTerm" :in-action="inAction" search-label="Zoek binnen producttypen"
            search-placeholder="bijv. 'Viergastester'" add-label="Voeg producttype toe" :paginator="productTypes"
            :pagination-params="{ search: searchTerm }" @add="() => typeFormRef?.show()" />
    </div>

    <!-- Form box -->
    <div class="mt-0 mb-4" v-auto-animate>
        <CreateRecordForm ref="typeFormRef" external-trigger action="/producttypes" :fields="typeFields"
            add-button-label="Voeg producttype toe" submit-label="Toevoegen" />
    </div>

    <!-- Content box -->
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <EditableGridComponent :headers="headers" :items="innerTypes" @update="onCellUpdate" urlBase="producttypes" />
        <PaginationComponent v-if="innerTypes.length" :paginator="productTypes" :params="{ search: searchTerm }"
            class="border-t border-gray-200 pt-2" />
        <div v-else class="text-center text-gray-500 p-4">
            <p>Geen producttypen gevonden.</p>
            <p>Probeer een andere zoekterm of voeg een nieuw producttype toe.</p>
        </div>
    </BoxComponent>
</template>

<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch, onMounted, computed } from 'vue';
import debounce from 'lodash/debounce';
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

const inAction = ref(false);
const typeFields = [
    { key: 'name', label: 'Naam', type: 'text' },
    { key: 'typical_certificate_days', label: 'Keuringsduur (dagen)', type: 'number', default: 0 },
]

// Creation handled by backend redirect; no client-side mutations needed.

const innerTypes = computed(() => props.productTypes?.data || [])
const searchTerm = ref(props.search);

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 70 },
    { key: 'typical_certificate_days', label: 'Keuringsduur (dagen)', fieldtype: 'number' },
]

const form = useForm({ name: null, typical_certificate_days: null });
function onCellUpdate({ item }) {
    form.transform(() => ({ ...item }))
        .put(`/producttypes/${item.id}`, { preserveScroll: true });
}

// Debounced search function
const searchTypes = debounce((term) => {
    inAction.value = true;
    localStorage.setItem('searchInitiated', 'true');
    router.get(`/producttypes?search=${term}`, {}, { preserveScroll: true });
}, 300);

// Watch for changes to searchTerm
watch(searchTerm, newTerm => {
    searchTypes(newTerm);
});

onMounted(() => {
    if (localStorage.getItem('searchInitiated') === 'true') {
        inAction.value = false;
        localStorage.removeItem('searchInitiated');
        document.getElementById('searchInput').focus();
    }
});
</script>
