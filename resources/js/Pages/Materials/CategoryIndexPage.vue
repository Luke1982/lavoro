<template>
    <div class="p-4 bg-white rounded-md mb-3">
        <IndexHeaderComponent title="Materiaalcategorieën" subtitle="Beheer van categorieën" :show-search="false"
            add-label="Voeg categorie toe" @add="() => categoryFormRef?.show()" />
    </div>
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="categoryFormRef" external-trigger action="/materialcategories" :fields="categoryFields"
            add-button-label="Voeg categorie toe" submit-label="Toevoegen" />
    </div>
    <EditableGridComponent :headers="headers" :items="innerCategories" @update="onCellUpdate" :urlBase="urlBase" />
</template>
<script setup>
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue';
import { useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
const categoryFormRef = ref(null)

const { categories } = defineProps({
    categories: {
        type: Array,
        default: () => []
    }
})

const innerCategories = computed(() => categories)
const urlBase = 'materialcategories';

const categoryFields = [
    { key: 'name', label: 'Naam', type: 'text' },
]

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 'w-full' },
];

const form = useForm({
    name: null,
});

function onCellUpdate({ item }) {
    form.transform(() => {
        return {
            ...item
        }
    }).put(`/${urlBase}/${item.id}`, {
        preserveScroll: true,
    });
}

// Creation handled by backend redirect; no client-side mutations needed.
</script>