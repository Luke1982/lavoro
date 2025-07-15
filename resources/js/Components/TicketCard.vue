<template>
    <div class="relative">
        <Link :href="`/tickets/${ticket.id}`"
            :class="[getWrapperClasses(ticket.status), 'rounded-md p-2 border-2 block']">
        <div class="flex">
            <div class="flex flex-col items-center justify-start mr-2 w-20">
                <ExclamationCircleIcon
                    :class="[getExclamationMarkClasses(ticket.priority, ticket.status), 'w-6 h-6']" />
                <span :class="[getExclamationMarkClasses(ticket.priority, ticket.status), 'text-xs']">Prio {{
                    ticket.priority }}</span>
            </div>
            <div class="flex flex-col">
                <h1 class="text-l font-bold">{{ ticket.subject }}</h1>
                <div class="flex mt-1">
                    <CalendarDateRangeIcon class="w-5 h-5 text-gray-500 mr-1" />
                    <div class="text-sm text-gray-500">
                        <span>Sinds {{ nlDate(ticket.created_at) }}</span>
                        <span v-if="ticket.closed_on && ticket.status.toLowerCase() === 'gesloten'">, Gesloten op {{
                            nlDate(ticket.closed_on) }}</span>
                    </div>
                </div>
            </div>
        </div>
        </Link>
        <TrashIcon class="w-5 h-5 text-gray-500 top-2 right-2 cursor-pointer absolute" @click.stop="deleteTicket" />
    </div>
</template>

<script setup>
import { CalendarDateRangeIcon, ExclamationCircleIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';
import { nlDate } from '@/Utilities/Utilities';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    ticket: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    ticketId: props.ticket.id,
});

const getExclamationMarkClasses = (priority, status) => {
    const lowerPriority = priority.toLowerCase();
    const lowerStatus = status.toLowerCase();
    if (lowerStatus === 'gesloten') {
        return 'text-gray-500';
    }
    if (lowerPriority === 'hoog') {
        return 'text-red-700';
    } else if (lowerPriority === 'laag') {
        return 'text-green-700';
    } else if (lowerPriority === 'normaal') {
        return 'text-yellow-700';
    } else {
        return 'text-gray-700';
    }
}

const getWrapperClasses = status => {
    const lowerStatus = status.toLowerCase();
    if (lowerStatus === 'open') {
        return 'bg-red-50 border-red-200 text-red-700';
    } else if (lowerStatus === 'gesloten') {
        return 'bg-green-50 border-green-200 text-green-700';
    } else if (lowerStatus === 'in behandeling') {
        return 'bg-yellow-50 border-yellow-200 text-yellow-700';
    } else {
        return 'bg-gray-50 border-gray-200 text-gray-700';
    }
}

const deleteTicket = () => {
    if (confirm('Weet je zeker dat je deze storing wilt verwijderen?')) {
        form.delete(`/tickets/${props.ticket.id}`, {
            preserveScroll: true,
        });
    }
}
</script>