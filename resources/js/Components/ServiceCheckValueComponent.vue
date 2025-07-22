<template>
    <div class="flex">
        <div class="min-w-60 flex-grow">
            <TextInput v-model="form.value" :error="form.errors.value" :rightCorners="false" />
        </div>
        <button @click="form.patch(`/servicecheckvalues/${scValue.id}`, { preserveScroll: true });"
            v-tooltip="'Sla de waarde op'" class="px-3 py-1 bg-green-600 text-white cursor-pointer hover:bg-green-700">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" class="w-5 h-5" fill="currentColor">
                <path
                    d="M64 32C28.7 32 0 60.7 0 96L0 416c0 35.3 28.7 64 64 64l320 0c35.3 0 64-28.7 64-64l0-242.7c0-17-6.7-33.3-18.7-45.3L352 50.7C340 38.7 323.7 32 306.7 32L64 32zm0 96c0-17.7 14.3-32 32-32l192 0c17.7 0 32 14.3 32 32l0 64c0 17.7-14.3 32-32 32L96 224c-17.7 0-32-14.3-32-32l0-64zM224 288a64 64 0 1 1 0 128 64 64 0 1 1 0-128z" />
            </svg>
        </button>
        <button @click="deleteValue" v-tooltip="'Verwijder de waarde'"
            class="px-3 py-1 bg-red-600 text-white rounded-r cursor-pointer hover:bg-red-700">
            <TrashIcon class="w-5 h-5" />
        </button>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { defineEmits } from 'vue';
import TextInput from './UI/TextInput.vue';
import { TrashIcon } from '@heroicons/vue/24/outline';

const emit = defineEmits(['delete']);

const props = defineProps({
    scValue: {
        type: Object,
        required: true
    }
});

const form = useForm({
    value: props.scValue.value,
    service_check_id: props.scValue.service_check_id
});

const deleteValue = () => {
    if (confirm('Weet je zeker dat je deze waarde wilt verwijderen?')) {
        form.delete(`/servicecheckvalues/${props.scValue.id}`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    emit('delete', props.scValue.id);
                }
            }
        );
    }
};
</script>