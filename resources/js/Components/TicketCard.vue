<template>
    <div class="relative">
        <Link :href="`/tickets/${ticket.id}`"
            :class="[getWrapperClasses(ticket.status), 'rounded-md p-2 border-2 block cursor-pointer transition-colors']">
        <div class="flex">
            <div class="flex flex-col items-center justify-start mr-2 min-w-20 pb-4 text-center">
                <ExclamationCircleIcon
                    :class="[getExclamationMarkClasses(ticket.priority, ticket.status), 'w-6 h-6']" />
                <span :class="[getExclamationMarkClasses(ticket.priority, ticket.status), 'text-xs']">Prio {{
                    ticket.priority }}</span>
            </div>
            <div class="flex flex-col">
                <h1 class="text-l font-bold dark:text-slate-100">{{ ticket.subject }}</h1>
                <div class="flex mt-1">
                    <CalendarDateRangeIcon class="w-5 h-5 text-gray-800 dark:text-slate-300 mr-1" />
                    <div class="text-sm text-gray-800 dark:text-slate-300">
                        <span>Sinds {{ nlDate(ticket.created_at) }}</span>
                        <span v-if="ticket.closed_on && ticket.status.toLowerCase() === 'gesloten'">, Gesloten op {{
                            nlDate(ticket.closed_on) }}</span>
                    </div>
                </div>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1"
                    v-if="modes.find(m => m === 'simple') === undefined">{{
                        ticket.description }}</p>
            </div>
        </div>
        </Link>
        <div class="absolute top-2 right-2 flex items-center space-x-2">
            <CheckIcon v-if="ticket.status.toLowerCase() !== 'gesloten' && hasPermission('ticket.change_status')"
                class="w-5 h-5 text-green-500 dark:text-green-400 cursor-pointer"
                @click.stop="setTicketStatusTo('Gesloten')" v-tooltip="'Wijzig de status naar \'Gesloten\''" />
            <ClockIcon v-if="ticket.status.toLowerCase() !== 'in behandeling' && hasPermission('ticket.change_status')"
                class="w-5 h-5 text-blue-500 dark:text-blue-400 cursor-pointer"
                @click.stop="setTicketStatusTo('In behandeling')"
                v-tooltip="'Wijzig de status naar \'In behandeling\''" />
            <NoSymbolIcon v-if="ticket.status.toLowerCase() !== 'open' && hasPermission('ticket.change_status')"
                class="w-5 h-5 text-red-500 dark:text-red-400 cursor-pointer" @click.stop="setTicketStatusTo('Open')"
                v-tooltip="'Wijzig de status naar \'Open\''" />
            <TrashIcon v-if="disconnect === null && modes.find(m => m === 'nodelete') === undefined"
                class="w-5 h-5 text-gray-500 dark:text-slate-400 cursor-pointer" @click.stop="deleteTicket"
                v-tooltip="'Verwijder de storing'" />
            <LinkSlashIcon v-if="disconnect !== null && hasPermission('ticket.detach_from_serviceorder')"
                class="w-5 h-5 text-gray-500 dark:text-slate-400 cursor-pointer" @click.stop="removeTicketLink"
                v-tooltip="'Verwijder de storing van deze werkbon'" />
        </div>
        <div class="absolute bottom-2 left-2 w-20">
            <ChevronDownIcon
                v-if="ticket.priority.toLowerCase() !== ticketPriorities[0].id.toLowerCase() && hasPermission('ticket.alter_priority')"
                class="w-5 h-5 text-gray-500 cursor-pointer absolute left-0 bottom-0"
                v-tooltip="'Verlaag de prioriteit'" @click.stop="alterTicketPrio('down')" />
            <ChevronUpIcon
                v-if="ticket.priority.toLowerCase() !== ticketPriorities[ticketPriorities.length - 1].id.toLowerCase() && hasPermission('ticket.alter_priority')"
                class="w-5 h-5 text-gray-500 cursor-pointer absolute right-0 bottom-0"
                v-tooltip="'Verhoog de prioriteit'" @click.stop="alterTicketPrio('up')" />
        </div>
    </div>
</template>

<script setup>
import { CalendarDateRangeIcon, CheckIcon, ChevronDownIcon, ChevronUpIcon, ClockIcon, ExclamationCircleIcon, LinkSlashIcon, NoSymbolIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';
import { nlDate } from '@/Utilities/Utilities';
import { useForm } from '@inertiajs/vue3';
import { ticketPriorities } from './data/TicketData';
import { hasPermission } from '@/Utilities/Utilities';

const props = defineProps({
    ticket: {
        type: Object,
        required: true,
    },
    disconnect: {
        type: String,
        default: null,
    },
    modes: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    ticketId: props.ticket.id,
    status: props.ticket.status,
    priority: props.ticket.priority,
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
        return 'bg-red-50 border-red-200 text-red-700 dark:bg-red-900/30 dark:border-red-700 dark:text-red-300';
    } else if (lowerStatus === 'gesloten') {
        return 'bg-green-50 border-green-200 text-green-700 dark:bg-green-900/30 dark:border-green-700 dark:text-green-300';
    } else if (lowerStatus === 'in behandeling') {
        return 'bg-yellow-50 border-yellow-200 text-yellow-700 dark:bg-yellow-900/30 dark:border-yellow-700 dark:text-yellow-300';
    } else {
        return 'bg-gray-50 border-gray-200 text-gray-700 dark:bg-slate-800 dark:border-slate-500 dark:text-slate-200';
    }
}

const deleteTicket = () => {
    if (confirm('Weet je zeker dat je deze storing wilt verwijderen?')) {
        form.delete(`/tickets/${props.ticket.id}`, {
            preserveScroll: true,
        });
    }
}

const removeTicketLink = () => {
    if (confirm('Weet je zeker dat je deze storing van de werkbon wilt verwijderen?')) {
        form.get(`/serviceorders/${props.ticket.service_order_id}/tickets/${props.ticket.id}/detach`, {
            preserveScroll: true,
            preserveState: true,
        });
    }
}

const setTicketStatusTo = (status) => {
    form.status = status;
    form.put(`/tickets/${props.ticket.id}`, {
        preserveScroll: true,
    });
}

const alterTicketPrio = (dir) => {
    const idx = ticketPriorities.findIndex(
        p => p.id.toLowerCase() === props.ticket.priority.toLowerCase()
    );

    const newIndex = dir === 'up' ? idx + 1 : idx - 1;
    form.priority = ticketPriorities[newIndex].id;
    form.put(`/tickets/${props.ticket.id}`, {
        preserveScroll: true,
    });
}
</script>