<template>
    <EditableGridComponent :headers="headers" :items="innerMaterials" @update="onCellUpdate" urlBase="materials" />
</template>
<script setup>
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const { materials, categories } = defineProps({
    materials: {
        type: Array,
        default: () => []
    },
    categories: {
        type: Array,
        default: () => []
    }
})

const innerMaterials = ref(materials);

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 'w-1/3' },
    { key: 'code', label: 'Code', fieldtype: 'text', width: 'w-1/9' },
    { key: 'price', label: 'Prijs', fieldtype: 'number', width: 'w-1/9' },
    { key: 'divisable', label: 'Deelbaar', fieldtype: 'boolean', width: 'w-20' },
    { key: 'is_active', label: 'Actief', fieldtype: 'boolean', width: 'w-20' },
    { key: 'material_category_id', label: 'Categorie', fieldtype: 'combobox', width: 'w-full', combovalues: categories },
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