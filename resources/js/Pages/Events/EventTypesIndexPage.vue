<template>
    <EditableGridComponent :headers="headers" :items="innerTypes" @update="onCellUpdate" :urlBase="urlBase" />
</template>
<script setup>
import EditableGridComponent from '@/Components/UI/EditableGridComponent.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const { eventTypes } = defineProps({
    eventTypes: {
        type: Array,
        default: () => []
    }
})

const innerTypes = ref(eventTypes);
const urlBase = 'eventtypes';

const headers = [
    { key: 'name', label: 'Naam', fieldtype: 'text', width: 'w-100' },
    { key: 'description', label: 'Beschrijving', fieldtype: 'text', width: 'w-100' },
    { key: 'color', label: 'Kleur', fieldtype: 'colorpicker', width: 'w-40' },
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