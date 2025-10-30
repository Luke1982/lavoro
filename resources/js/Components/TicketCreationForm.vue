<template>
    <div class="flex flex-wrap">
        <div class="w-full md:w-1/3 md:pr-1 mb-2 md:mb-0">
            <TextInput v-model="form.subject" placeholder="Onderwerp" :error-message="form.errors.subject"
                :has-error="form.errors.subject !== undefined" name="subject" id="subject" autocomplete="off"
                error-id="subject" />
        </div>
        <div class="w-full md:w-2/3 md:pl-1">
            <TextInput v-model="form.description" placeholder="Beschrijving" :error-message="form.errors.description"
                :has-error="form.errors.description !== undefined" />
        </div>
        <div class="pr-1 pt-2 w-1/2">
            <ComboBox :options="ticketStatusses" v-model="form.status" />
        </div>
        <div class="pl-1 pt-2 w-1/2">
            <ComboBox :options="ticketPriorities" v-model="form.priority" />
        </div>
    </div>
    <div class="flex justify-end pt-2">
        <button @click.prevent="emit('close')"
            class="mr-2 inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md text-xs text-gray-700 tracking-widest hover:bg-gray-300 active:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition ease-in-out duration-150 cursor-pointer">
            Annuleren
        </button>
        <button @click.prevent="addTicket"
            class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-xs text-white tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition ease-in-out duration-150 cursor-pointer">
            Storing aanmaken
        </button>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import TextInput from './UI/TextInput.vue';
import ComboBox from './UI/ComboBox.vue';
import { ticketStatusses, ticketPriorities } from './data/TicketData';
import { defineEmits } from 'vue';

const props = defineProps({
    assetId: {
        type: Number,
        required: false,
        default: null,
    },
});

const form = useForm({
    asset_id: props.assetId,
    subject: '',
    description: '',
    priority: 'Hoog',
    status: 'Open',
});

const emit = defineEmits(['close']);

const addTicket = () => {
    form.post(`/tickets`, {
        onSuccess: () => {
            form.reset();
            emit('close');
        },
    });
};

</script>