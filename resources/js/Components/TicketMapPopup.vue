<template>
    <div class="min-w-[240px]">
        <h3 class="font-bold text-base mb-1">{{ customer.name }}</h3>
        <p class="text-sm text-gray-600 mb-2">
            {{ formatAddress(customer) }}
        </p>

        <div v-if="customer.tickets?.length" class="border-t border-gray-200 pt-2 mt-2 max-h-[200px] overflow-y-auto">
            <div v-for="ticket in customer.tickets" :key="ticket.id" class="mb-2 last:mb-0 text-sm">
                <div class="flex justify-between items-baseline gap-2">
                    <a :href="`/tickets/${ticket.id}`" class="font-medium text-blue-700 underline truncate"
                        :title="ticket.subject">
                        {{ ticket.subject }}
                    </a>
                    <span
                        :class="['inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset shrink-0', ticketPriorityClasses(ticket.priority)]">
                        {{ ticket.priority }}
                    </span>
                </div>
                <div class="mt-0.5">
                    <span
                        :class="['inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset', ticketStatusClasses(ticket.status)]">
                        {{ ticket.status }}
                    </span>
                </div>
            </div>
        </div>
        <div v-else class="text-sm text-gray-500 italic mt-2 border-t pt-2">
            Geen storingen
        </div>
    </div>
</template>

<script setup>
import { formatAddress, ticketPriorityClasses, ticketStatusClasses } from '@/Utilities/Utilities';

defineProps({
    customer: { type: Object, required: true }
});
</script>
