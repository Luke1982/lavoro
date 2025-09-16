<template>
    <div class="relative">
        <TicketCard :ticket="ticket" class="mt-1 mb-1" :modes="['simple', 'nodelete']" />
        <input type="checkbox" :id="`ticketcheckbox-${ticket.id}`" v-model="selectedTickets"
            :value="{ id: ticket.id, customer_id: customerId }"
            class="absolute top-2 left-2 size-5 cursor-pointer hidden" />
        <label :for="`ticketcheckbox-${ticket.id}`" class="absolute left-0 top-0 w-full h-full cursor-pointer">
            <CheckIcon v-if="selectedTickets.find(t => t.id === ticket.id)"
                class="size-6 ml-1 mt-1 text-blue-500 dark:text-blue-400 drop-shadow"
                v-tooltip="'Deze storing is geselecteerd voor de werkbon'" />
        </label>
    </div>
</template>

<script setup>
import TicketCard from '@/Components/TicketCard.vue';
import { CheckIcon } from '@heroicons/vue/24/outline';
import { computed } from 'vue';

const props = defineProps({
    ticket: { type: Object, required: true },
    customerId: { type: [Number, String], required: true },
    modelValue: { type: Array, required: true },
});

const emit = defineEmits(['update:modelValue']);

const selectedTickets = computed({
    get: () => props.modelValue,
    set: v => emit('update:modelValue', v),
});
</script>