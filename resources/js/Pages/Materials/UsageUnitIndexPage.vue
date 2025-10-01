<template>
    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 border dark:border-slate-800">
        <IndexHeaderComponent title="Gebruikseenheden" subtitle="Beheer van gebruikseenheden" :show-search="false"
            add-label="Voeg gebruikseenheid toe" @add="() => unitFormRef?.show()" />
    </div>
    <div class="mb-4" v-auto-animate>
        <CreateRecordForm ref="unitFormRef" external-trigger action="/materialusageunits" :fields="unitFields"
            add-button-label="Voeg gebruikseenheid toe" submit-label="Toevoegen" />
    </div>
    <EditableGridComponent :headers="headers" :items="innerUnits" @update="onCellUpdate" :urlBase="urlBase" />
</template>
<script setup>
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue';
import { useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
const unitFormRef = ref(null)

const { usageUnits } = defineProps({
    usageUnits: {
        type: Array,
        default: () => []
    }
})

const innerUnits = computed(() => usageUnits)
const urlBase = 'materialusageunits';

const unitFields = [
    { key: 'name', label: 'Naam', type: 'text', class: 'w-full' },
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