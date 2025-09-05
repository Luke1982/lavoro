<template>
    <div class="p-4 bg-white rounded-md mb-3">
        <IndexHeaderComponent title="Materialen" subtitle="Zoek binnen materialen" search-url="/materials"
            search-label="Zoek binnen materialen" search-placeholder="Zoek op naam, code of categorie"
            add-label="Voeg materiaal toe" :paginator="materials" @add="() => materialFormRef?.show()" />
    </div>
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="materialFormRef" external-trigger action="/materials" :fields="materialFields"
            add-button-label="Voeg materiaal toe" submit-label="Toevoegen" />
    </div>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <EditableGridComponent :headers="headers" :items="innerMaterials" @update="onCellUpdate" urlBase="materials" />
        <PaginationComponent v-if="innerMaterials.length" :paginator="materials"
            class="border-t border-gray-200 pt-2 mt-2" />
    </BoxComponent>
</template>
<script setup>
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue';
import { useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import PaginationComponent from '@/Components/UI/PaginationComponent.vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import BoxComponent from '@/Components/BoxComponent.vue';
const materialFormRef = ref(null)

const { materials, categories, usageUnits } = defineProps({
    materials: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => []
    },
    usageUnits: {
        type: Array,
        default: () => []
    },
    search: { type: String, default: '' }
})

const innerMaterials = computed(() => materials?.data || [])

const materialFields = [
    { key: 'name', label: 'Naam', type: 'text' },
    { key: 'code', label: 'Code', type: 'text' },
    { key: 'price', label: 'Prijs', type: 'number', default: 0 },
    { key: 'material_category_id', label: 'Categorie', type: 'combobox', options: categories, initialId: categories[0]?.id },
    { key: 'material_usage_unit_id', label: 'Gebruikseenheid', type: 'combobox', options: usageUnits, initialId: usageUnits[0]?.id },
    { key: 'divisable', label: 'Deelbaar', type: 'boolean', class: 'w-auto' },
    { key: 'is_active', label: 'Actief', type: 'boolean', class: 'w-auto', default: true },
]
const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 22 },
    { key: 'code', label: 'Code', fieldtype: 'text', width: 12 },
    { key: 'price', label: 'Prijs', fieldtype: 'number', width: 12 },
    { key: 'divisable', label: 'Deelbaar', fieldtype: 'boolean', width: 8 },
    { key: 'is_active', label: 'Actief', fieldtype: 'boolean', width: 8 },
    { key: 'material_category_id', label: 'Categorie', fieldtype: 'combobox', width: 20, combovalues: categories },
    { key: 'material_usage_unit_id', label: 'Gebruikseenheid', fieldtype: 'combobox', combovalues: usageUnits },
];

const form = useForm({
    name: null,
    description: null,
    material_category_id: null,
    code: null,
    vendor_code: null,
    price: 0.00,
    cost_price: null,
    material_usage_unit_id: null,
    divisable: false,
    is_active: true,
    is_service: false,
    stock: 0.00,
    min_stock: 0.00,
    max_stock: 0.00,
});

function onCellUpdate({ item }) {
    form.transform(() => {
        return {
            ...item
        }
    }).put(`/materials/${item.id}`, {
        preserveScroll: true,
    });
}
</script>