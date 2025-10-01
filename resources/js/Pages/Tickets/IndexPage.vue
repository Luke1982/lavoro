<template>
    <div
        class="bg-white dark:bg-slate-900 rounded-md border border-gray-200 dark:border-slate-800/60 shadow-sm dark:shadow-none mb-4 overflow-hidden">
        <h2 class="sr-only">Ticket statistieken</h2>
        <div
            class="grid grid-cols-1 md:grid-cols-3 divide-y divide-gray-100 md:divide-y-0 md:divide-x md:divide-gray-100 dark:divide-slate-700/60">
            <div class="p-6">
                <StatCard label="Open" :value="openCount" :baseline="avgCount" :delta="openPctVsAvg" type="open" />
            </div>
            <div class="p-6">
                <StatCard label="In behandeling" :value="pendingCount" :baseline="avgCount" :delta="pendingPctVsAvg"
                    type="pending" />
            </div>
            <div class="p-6">
                <StatCard label="Gesloten" :value="closedCount" :baseline="avgCount" :delta="closedPctVsAvg"
                    type="closed" />
            </div>
        </div>
    </div>

    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 dark:border-slate-800 border">
        <IndexHeaderComponent title="Storingen" subtitle="Overzicht van alle storingen" search-url="/tickets"
            search-placeholder="Onderwerp, product, type, serienummer of klant" :paginator="tickets"
            :search-other-params="computedOtherParams">
            <template #right>
                <div class="flex flex-col md:flex-row gap-4 w-full">
                    <div class="flex-1">
                        <ComboBox :options="statusOptions" v-model="selectedStatuses" multiple label="Statussen"
                            placeholder="Filter op status" :initial-ids="selectedStatuses" />
                    </div>
                    <div class="flex-1">
                        <ComboBox :options="priorityOptions" v-model="selectedPriorities" multiple label="Prioriteiten"
                            placeholder="Filter op prioriteit" :initial-ids="selectedPriorities" />
                    </div>
                </div>
            </template>
        </IndexHeaderComponent>
    </div>

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="tickets.data.length"
            class="mt-3 sm:-mx-0 rounded-md border border-gray-300 dark:border-slate-700/60 bg-white dark:bg-slate-900 p-px"
            role="table">
            <div class="hidden lg:flex" role="row">
                <div v-for="(h, i) in headers" :key="h.key"
                    :class="['px-4 py-2 text-left text-sm font-semibold text-white dark:text-slate-100 bg-gray-600 dark:bg-slate-700 first:rounded-tl-md', i === headers.length - 1 ? 'last:rounded-tr-md' : '', classForIndex(i)]"
                    :style="styleForIndex(i)">
                    {{ h.label }}
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900" role="rowgroup" v-auto-animate>
                <div v-for="ticket in tickets.data" :key="ticket.id" role="row"
                    class="border-t border-gray-200 dark:border-slate-800/60 first:border-t-0 flex flex-col lg:flex-row">
                    <div v-for="(h, i) in headers" :key="h.key"
                        :class="['px-4 py-3 text-sm lg:flex lg:items-center lg:py-2 space-y-1 lg:space-y-0', classForIndex(i)]"
                        :style="styleForIndex(i)">
                        <span
                            class="block lg:hidden text-[11px] uppercase tracking-wide text-gray-500 dark:text-slate-400 font-medium">{{
                                h.label }}</span>
                        <div v-if="h.key === 'subject'" class="font-medium">
                            <Link :href="`/tickets/${ticket.id}`" class="text-blue-600 dark:text-blue-400 underline">{{
                                ticket.subject }}
                            </Link>
                            <div class="mt-1 text-xs text-gray-500 dark:text-slate-400 lg:hidden">
                                {{ nlDate(ticket.created_at) }} • {{ ticket.status }}
                            </div>
                        </div>
                        <div v-else-if="h.key === 'product'">
                            <span class="text-gray-800 dark:text-slate-200">{{ ticket.asset.product.brand.name }} {{
                                ticket.asset.product.model }}</span>
                        </div>
                        <div v-else-if="h.key === 'product_type'">
                            <Link :href="`/producttypes?search=${ticket.asset.product.product_type.name}`"
                                class="underline text-gray-800 dark:text-slate-200">
                            {{ ticket.asset.product.product_type.name }}
                            </Link>
                        </div>
                        <div v-else-if="h.key === 'serial'">
                            <Link :href="`/assets/${ticket.asset.id}`"
                                class="underline text-gray-800 dark:text-slate-200">
                            {{ ticket.asset.serial_number }}
                            </Link>
                        </div>
                        <div v-else-if="h.key === 'customer'">
                            <Link :href="`/customers/${ticket.asset.customer.id}`"
                                class="underline text-gray-800 dark:text-slate-200">
                            {{ ticket.asset.customer.name }}
                            </Link>
                        </div>
                        <div v-else-if="h.key === 'status'">
                            <span
                                :class="['inline-flex items-center rounded px-2 py-1 text-xs font-medium ring-1 ring-inset', statusClasses(ticket.status)]">
                                {{ ticket.status }}
                            </span>
                        </div>
                        <div v-else-if="h.key === 'priority'">
                            <span
                                :class="['inline-flex items-center rounded px-2 py-1 text-xs font-medium ring-1 ring-inset', priorityClasses(ticket.priority)]">
                                {{ ticket.priority }}
                            </span>
                        </div>
                        <div v-else-if="h.key === 'created'" class="text-gray-800 dark:text-slate-200">
                            {{ nlDate(ticket.created_at) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <PaginationComponent v-if="tickets.data.length" :paginator="tickets"
            class="border-t border-gray-200 dark:border-slate-700/60 pt-2" />
        <p v-else class="text-center text-gray-500 dark:text-slate-400 p-4">Geen storingen gevonden.</p>
    </BoxComponent>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import PaginationComponent from '@/Components/UI/PaginationComponent.vue';
import { Link } from '@inertiajs/vue3';
import ComboBox from '@/Components/UI/ComboBox.vue';
import { computed, ref } from 'vue';
import { nlDate } from '@/Utilities/Utilities';
import StatCard from '@/Components/UI/StatCard.vue';

const props = defineProps({
    tickets: { type: Object, required: true },
    search: { type: String, default: '' },
    openCount: { type: Number, required: true },
    pendingCount: { type: Number, required: true },
    closedCount: { type: Number, required: true },
    avgCount: { type: Number, required: true },
    openPctVsAvg: { type: Number, required: true },
    pendingPctVsAvg: { type: Number, required: true },
    closedPctVsAvg: { type: Number, required: true },
    activeStatuses: { type: Array, default: () => [] },
    activePriorities: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    priorityOptions: { type: Array, default: () => [] },
});

const selectedStatuses = ref(props.activeStatuses.slice());
const selectedPriorities = ref(props.activePriorities.slice());

const computedOtherParams = computed(() => ({
    statuses: selectedStatuses.value.join(','),
    priorities: selectedPriorities.value.join(','),
}));

// Adjusted widths so table fits typical desktop widths without horizontal scroll.
const headers = [
    { key: 'subject', label: 'Onderwerp', width: 20 },
    { key: 'product', label: 'Product', width: 16 },
    { key: 'product_type', label: 'Type', width: 13 },
    { key: 'serial', label: 'Serienummer', width: 11 },
    { key: 'customer', label: 'Klant', width: 14 },
    { key: 'status', label: 'Status', width: 8 },
    { key: 'priority', label: 'Prioriteit', width: 8 },
    { key: 'created', label: 'Aangemaakt' },
];

function classForIndex(index) {
    if (index === headers.length - 1) return 'lg-grow';
    const w = headers[index]?.width;
    if (typeof w === 'number' && isFinite(w)) return 'lg-col';
    return 'lg-col-auto';
}
function styleForIndex(index) {
    if (index === headers.length - 1) return {};
    const w = headers[index]?.width;
    if (typeof w === 'number' && isFinite(w)) return { '--col-w': `${w}%` };
    return {};
}

function statusClasses(status) {
    const s = (status || '').toLowerCase();
    if (s === 'open') {
        return 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-700/50';
    }
    if (s === 'in behandeling') {
        return 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-700/50';
    }
    if (s === 'gesloten') {
        return 'bg-green-50 text-green-700 ring-green-200 dark:bg-green-900/30 dark:text-green-300 dark:ring-green-700/50';
    }
    return 'bg-gray-50 text-gray-700 ring-gray-200 dark:bg-slate-800/60 dark:text-slate-200 dark:ring-slate-600/60';
}

function priorityClasses(priority) {
    if (!priority) {
        return 'bg-gray-100 text-gray-700 ring-gray-300 dark:bg-slate-800/60 dark:text-slate-200 dark:ring-slate-600/60';
    }
    const p = priority.toLowerCase();
    if (p === 'hoog') {
        return 'bg-red-100 text-red-700 ring-red-300 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-700/50';
    }
    if (p === 'normaal') {
        return 'bg-yellow-100 text-yellow-700 ring-yellow-300 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-700/50';
    }
    if (p === 'laag') {
        return 'bg-green-100 text-green-700 ring-green-300 dark:bg-green-900/30 dark:text-green-300 dark:ring-green-700/50';
    }
    return 'bg-gray-100 text-gray-700 ring-gray-300 dark:bg-slate-800/60 dark:text-slate-200 dark:ring-slate-600/60';
}

</script>


<style scoped>
@media (min-width: 1024px) {
    .lg-col {
        flex: 0 0 var(--col-w);
        width: var(--col-w);
    }

    .lg-grow {
        flex: 1 1 0%;
        min-width: 0;
    }

    .lg-col-auto {
        flex: 0 0 auto;
    }
}
</style>
