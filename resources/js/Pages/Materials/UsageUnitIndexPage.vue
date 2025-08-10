<template>
    <EditableGridComponent :headers="headers" :items="innerUnits" @update="onCellUpdate" :urlBase="urlBase" />
</template>
<script setup>
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const { usageUnits } = defineProps({
    usageUnits: {
        type: Array,
        default: () => []
    }
})

const innerUnits = ref(usageUnits);
const urlBase = 'materialusageunits';

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
</script>