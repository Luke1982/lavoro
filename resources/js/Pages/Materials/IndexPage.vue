<template>
    <!-- Header box -->
    <div class="p-4 bg-white rounded-md mb-3">
        <IndexHeaderComponent title="Materialen" subtitle="Zoek binnen materialen" v-model="searchTerm"
            search-label="Zoek binnen materialen" search-placeholder="Zoek op naam, code of categorie"
            :in-action="inAction" add-label="Voeg materiaal toe" :paginator="materials"
            :pagination-params="{ search: searchTerm }" @add="() => materialFormRef?.show()" />
    </div>
    <!-- Form box -->
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="materialFormRef" external-trigger action="/materials" :fields="materialFields"
            add-button-label="Voeg materiaal toe" submit-label="Toevoegen" @created="onMaterialCreated" />
    </div>
    <!-- Content box -->
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <EditableGridComponent :headers="headers" :items="innerMaterials" @update="onCellUpdate" urlBase="materials" />
        <PaginationComponent v-if="innerMaterials.length" :paginator="materials" :params="{ search: searchTerm }"
            class="border-t border-gray-200 pt-2 mt-2" />
    </BoxComponent>
</template>
<script setup>
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue';
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch, onMounted } from 'vue';
import debounce from 'lodash/debounce';
import PaginationComponent from '@/Components/UI/PaginationComponent.vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import BoxComponent from '@/Components/BoxComponent.vue';
const materialFormRef = ref(null)

const { materials, categories, usageUnits, search: initialSearch } = defineProps({
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

const innerMaterials = ref(materials.data);
const searchTerm = ref(initialSearch)
const inAction = ref(false)

const materialFields = [
    { key: 'name', label: 'Naam', type: 'text' },
    { key: 'code', label: 'Code', type: 'text' },
    { key: 'price', label: 'Prijs', type: 'number', default: 0 },
    { key: 'material_category_id', label: 'Categorie', type: 'combobox', options: categories, initialId: categories[0]?.id },
    { key: 'material_usage_unit_id', label: 'Gebruikseenheid', type: 'combobox', options: usageUnits, initialId: usageUnits[0]?.id },
    { key: 'divisable', label: 'Deelbaar', type: 'boolean', class: 'w-auto' },
    { key: 'is_active', label: 'Actief', type: 'boolean', class: 'w-auto', default: true },
]

function onMaterialCreated(newMaterial) {
    if (!newMaterial) return
    innerMaterials.value.push({ ...newMaterial })
    innerMaterials.value.sort((a, b) => a.name.localeCompare(b.name))
}

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 'w-1/3' },
    { key: 'code', label: 'Code', fieldtype: 'text', width: 'w-1/9' },
    { key: 'price', label: 'Prijs', fieldtype: 'number', width: 'w-1/9' },
    { key: 'divisable', label: 'Deelbaar', fieldtype: 'boolean', width: 'w-20' },
    { key: 'is_active', label: 'Actief', fieldtype: 'boolean', width: 'w-20' },
    { key: 'material_category_id', label: 'Categorie', fieldtype: 'combobox', width: 'w-full', combovalues: categories },
    { key: 'material_usage_unit_id', label: 'Gebruikseenheid', fieldtype: 'combobox', width: 'w-full', combovalues: usageUnits },
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

// Debounced search
const searchMaterials = debounce((term) => {
    inAction.value = true
    localStorage.setItem('searchInitiated', 'true')
    router.get(`/materials?search=${term}`, {}, { preserveScroll: true })
}, 300)

watch(searchTerm, newTerm => {
    searchMaterials(newTerm)
})

onMounted(() => {
    if (localStorage.getItem('searchInitiated') === 'true') {
        inAction.value = false
        localStorage.removeItem('searchInitiated')
        document.getElementById('searchInput')?.focus()
    }
})
</script>