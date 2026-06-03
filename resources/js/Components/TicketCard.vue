<template>
    <div :class="[getWrapperClasses(ticket.status), 'relative rounded-xl border p-4']">
        <div class="flex items-start gap-3">
            <div
                :class="[getIconBg(ticket.status), 'flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center']">
                <ExclamationCircleIcon :class="[getIconColor(ticket.status), 'w-6 h-6']" />
            </div>

            <div class="flex-1 min-w-0">
                <div class="flex items-start justify-between gap-2">
                    <h1 class="font-bold text-gray-900 dark:text-slate-100 text-sm leading-tight">{{ ticket.subject }}
                    </h1>
                    <div class="flex items-center gap-1 flex-shrink-0">
                        <Menu v-if="hasPermission('ticket.change_status')" as="div" class="relative">
                            <MenuButton
                                :class="[getStatusBadgeClass(ticket.status), 'inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-full cursor-pointer']">
                                {{ ticket.status }}
                                <ChevronDownIcon class="w-3 h-3" />
                            </MenuButton>
                            <MenuItems
                                class="absolute right-0 top-full mt-1 w-44 bg-white dark:bg-slate-800 shadow-lg rounded-lg border border-gray-200 dark:border-slate-700 focus:outline-none z-20 py-1">
                                <MenuItem v-if="ticket.status.toLowerCase() !== 'open'" v-slot="{ active }">
                                    <button @click="setTicketStatusTo('Open')"
                                        :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'block w-full text-left px-3 py-2 text-sm text-gray-700 dark:text-slate-200']">Open</button>
                                </MenuItem>
                                <MenuItem v-if="ticket.status.toLowerCase() !== 'in behandeling'" v-slot="{ active }">
                                    <button @click="setTicketStatusTo('In behandeling')"
                                        :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'block w-full text-left px-3 py-2 text-sm text-gray-700 dark:text-slate-200']">In
                                        behandeling</button>
                                </MenuItem>
                                <MenuItem v-if="ticket.status.toLowerCase() !== 'gesloten'" v-slot="{ active }">
                                    <button @click="setTicketStatusTo('Gesloten')"
                                        :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'block w-full text-left px-3 py-2 text-sm text-gray-700 dark:text-slate-200']">Gesloten</button>
                                </MenuItem>
                            </MenuItems>
                        </Menu>
                        <span v-else
                            :class="[getStatusBadgeClass(ticket.status), 'inline-flex items-center text-xs font-semibold px-2.5 py-1 rounded-full']">
                            {{ ticket.status }}
                        </span>

                        <Menu as="div" class="relative">
                            <MenuButton
                                class="bg-white p-1 rounded-full hover:bg-black/5 dark:hover:bg-white/10 text-gray-400 dark:text-slate-500 cursor-pointer">
                                <EllipsisVerticalIcon class="w-5 h-5" />
                            </MenuButton>
                            <MenuItems
                                class="absolute right-0 top-full mt-1 w-56 bg-white dark:bg-slate-800 shadow-lg rounded-lg border border-gray-200 dark:border-slate-700 focus:outline-none z-20 py-1">
                                <MenuItem
                                    v-if="ticket.status.toLowerCase() !== 'gesloten' && hasPermission('ticket.change_status')"
                                    v-slot="{ active }">
                                    <button @click="setTicketStatusTo('Gesloten')"
                                        :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex items-center gap-2.5 w-full text-left px-3 py-2.5 text-sm text-gray-700 dark:text-slate-200']">
                                        <CheckCircleIcon class="w-5 h-5 text-green-500 flex-shrink-0" />
                                        Markeer als opgelost
                                    </button>
                                </MenuItem>
                                <MenuItem
                                    v-if="ticket.status.toLowerCase() === 'gesloten' && hasPermission('ticket.change_status')"
                                    v-slot="{ active }">
                                    <button @click="setTicketStatusTo('Open')"
                                        :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex items-center gap-2.5 w-full text-left px-3 py-2.5 text-sm text-gray-700 dark:text-slate-200']">
                                        <XCircleIcon class="w-5 h-5 text-red-500 flex-shrink-0" />
                                        Markeer als niet opgelost
                                    </button>
                                </MenuItem>
                                <MenuItem v-if="disconnect !== null && hasPermission('ticket.detach_from_serviceorder')"
                                    v-slot="{ active }">
                                    <button @click="removeTicketLink"
                                        :class="[active ? 'bg-gray-50 dark:bg-slate-700' : '', 'flex items-center gap-2.5 w-full text-left px-3 py-2.5 text-sm text-gray-700 dark:text-slate-200']">
                                        <LinkSlashIcon class="w-5 h-5 text-gray-400 flex-shrink-0" />
                                        Loskoppelen van werk
                                    </button>
                                </MenuItem>
                                <MenuItem
                                    v-if="disconnect === null && modes.find(m => m === 'nodelete') === undefined && hasPermission('ticket.delete')"
                                    v-slot="{ active }">
                                    <button @click="deleteTicket"
                                        :class="[active ? 'bg-red-50 dark:bg-red-900/20' : '', 'flex items-center gap-2.5 w-full text-left px-3 py-2.5 text-sm text-red-600 dark:text-red-400']">
                                        <TrashIcon class="w-5 h-5 flex-shrink-0" />
                                        Verwijder storing
                                    </button>
                                </MenuItem>
                            </MenuItems>
                        </Menu>
                    </div>
                </div>

                <p v-if="ticket.asset?.product" class="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
                    {{ ticket.asset.product.product_type.name }}: {{ ticket.asset.product.brand.name }} {{
                        ticket.asset.product.model }} ({{ ticket.asset.serial_number }})
                </p>

                <div class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-slate-400 mt-1.5">
                    <CalendarDateRangeIcon class="w-4 h-4 flex-shrink-0" />
                    <span>Sinds {{ nlDate(ticket.created_at) }}</span>
                    <span v-if="ticket.closed_on && ticket.status.toLowerCase() === 'gesloten'"
                        class="text-gray-400 dark:text-slate-500"> · Gesloten {{ nlDate(ticket.closed_on) }}</span>
                </div>

                <p v-if="modes.find(m => m === 'simple') === undefined"
                    class="text-sm text-gray-500 dark:text-slate-400 mt-2">{{ ticket.description }}</p>

                <div class="mt-3">
                    <Link :href="`/tickets/${ticket.id}`"
                        class="bg-white inline-flex items-center gap-1.5 text-sm border border-gray-200 dark:border-slate-600 rounded-md px-3 py-1.5 text-gray-600 dark:text-slate-300 hover:bg-black/5 dark:hover:bg-white/5 transition-colors">
                        Bekijk storing
                        <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5" />
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { CalendarDateRangeIcon, ExclamationCircleIcon, LinkSlashIcon, TrashIcon, ChevronDownIcon, EllipsisVerticalIcon, ArrowTopRightOnSquareIcon, CheckCircleIcon, XCircleIcon } from '@heroicons/vue/24/outline';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Link, useForm } from '@inertiajs/vue3';
import { nlDate, hasPermission } from '@/Utilities/Utilities';

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
    status: props.ticket.status,
});

const getIconBg = (status) => {
    const s = status.toLowerCase();
    if (s === 'open') return 'bg-red-100 dark:bg-red-900/30';
    if (s === 'in behandeling') return 'bg-amber-100 dark:bg-amber-900/30';
    if (s === 'gesloten') return 'bg-green-100 dark:bg-green-900/30';
    return 'bg-gray-100 dark:bg-slate-700';
};

const getIconColor = (status) => {
    const s = status.toLowerCase();
    if (s === 'open') return 'text-red-600 dark:text-red-400';
    if (s === 'in behandeling') return 'text-amber-600 dark:text-amber-400';
    if (s === 'gesloten') return 'text-green-600 dark:text-green-400';
    return 'text-gray-500';
};

const getWrapperClasses = (status) => {
    const s = status.toLowerCase();
    if (s === 'open') return 'bg-red-50 dark:bg-red-950/20 border-red-200 dark:border-red-800/40';
    if (s === 'in behandeling') return 'bg-amber-50 dark:bg-amber-950/20 border-amber-200 dark:border-amber-800/40';
    if (s === 'gesloten') return 'bg-green-50 dark:bg-green-950/20 border-green-200 dark:border-green-800/40';
    return 'bg-white dark:bg-slate-800 border-gray-200 dark:border-slate-700';
};

const getStatusBadgeClass = (status) => {
    const s = status.toLowerCase();
    if (s === 'open') return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300';
    if (s === 'in behandeling') return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
    if (s === 'gesloten') return 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300';
    return 'bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-slate-300';
};

const deleteTicket = () => {
    if (confirm('Weet je zeker dat je deze storing wilt verwijderen?')) {
        form.delete(`/tickets/${props.ticket.id}`, { preserveScroll: true });
    }
};

const removeTicketLink = () => {
    if (confirm('Weet je zeker dat je deze storing van de werkbon wilt verwijderen?')) {
        form.get(`/serviceorders/${props.ticket.service_order_id}/tickets/${props.ticket.id}/detach`, {
            preserveScroll: true,
            preserveState: true,
        });
    }
};

const setTicketStatusTo = (status) => {
    form.status = status;
    form.transform(data => ({ status: data.status })).put(`/tickets/${props.ticket.id}`, { preserveScroll: true });
};
</script>
