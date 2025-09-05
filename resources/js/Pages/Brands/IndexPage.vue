<template>
    <!-- Header box -->
    <div class="p-4 bg-white rounded-md mb-3">
        <IndexHeaderComponent title="Merken" subtitle="Hieronder een lijst van alle merken" v-model="searchTerm"
            :in-action="inAction" search-label="Zoek binnen merken" search-placeholder="bijv. 'Verloop'"
            add-label="Voeg merk toe" :paginator="brands" :pagination-params="{ search: searchTerm }"
            @add="() => brandFormRef?.show()" />
    </div>

    <!-- Form box -->
    <div v-auto-animate class="mb-4">
        <CreateRecordForm ref="brandFormRef" external-trigger action="/brands" :fields="brandFields"
            add-button-label="Voeg merk toe" submit-label="Toevoegen" />
    </div>

    <!-- Content box -->
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <EditableGridComponent :headers="headers" :items="innerBrands" @update="onCellUpdate" urlBase="brands" />
        <PaginationComponent v-if="innerBrands.length > 0" :paginator="brands" :params="{ search: searchTerm }"
            class="border-t border-gray-200 pt-2" />
        <div v-else class="text-center text-gray-500 p-4">
            <p>Geen merken gevonden.</p>
            <p>Probeer een andere zoekterm of voeg een nieuw merk toe.</p>
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

const brandFormRef = ref(null)

const props = defineProps({
    brands: {
        type: Object,
        required: true,
    },
    search: {
        type: String,
        default: '',
    },
});

const inAction = ref(false);
const brandFields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

// Creation handled by backend redirect; no client-side mutations needed.

const innerBrands = computed(() => props.brands?.data || [])
const searchTerm = ref(props.search);
// pagination handled by PaginationComponent

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 70 },
];

const form = useForm({ name: null });
function onCellUpdate({ item }) {
    form.transform(() => ({ ...item }))
        .put(`/brands/${item.id}`, { preserveScroll: true });
}

// Debounced search function
const searchBrands = debounce((term) => {
    inAction.value = true;
    localStorage.setItem('searchInitiated', 'true');
    router.get(`brands?search=${term}`, {}, { preserveScroll: true });
}, 300);

// Watch for changes to searchTerm and call debounced search
watch(searchTerm, (newTerm) => {
    searchBrands(newTerm);
});

onMounted(() => {
    if (localStorage.getItem('searchInitiated') === 'true') {
        inAction.value = false;
        localStorage.removeItem('searchInitiated');
        document.getElementById('searchInput').focus();
    }
});

</script>
