<template>
    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 border border-gray-200 dark:border-slate-800 shadow-sm">
        <IndexHeaderComponent title="Merken" subtitle="Hieronder een lijst van alle merken" search-url="/brands"
            search-label="Zoek binnen merken" search-placeholder="bijv. 'Verloop'" add-label="Voeg merk toe"
            :paginator="brands" @add="() => brandFormRef?.show()" />
    </div>
    <div v-auto-animate class="mb-4">
        <CreateRecordForm ref="brandFormRef" external-trigger action="/brands" :fields="brandFields"
            add-button-label="Voeg merk toe" submit-label="Toevoegen" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <EditableGridComponent :headers="headers" :items="innerBrands" @update="onCellUpdate"
            urlBase="brands" />
        <PaginationComponent v-if="innerBrands.length > 0" :paginator="brands" class="border-t border-gray-200 pt-2" />
        <div v-else class="text-center text-gray-500 p-4">
            <p>Geen merken gevonden.</p>
            <p>Probeer een andere zoekterm of voeg een nieuw merk toe.</p>
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

const brandFields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

const innerBrands = computed(() => props.brands?.data || [])

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 70 },
];

const form = useForm({ name: null });
function onCellUpdate({ item }) {
    form.transform(() => ({ ...item }))
        .put(`/brands/${item.id}`, { preserveScroll: true });
}
</script>
