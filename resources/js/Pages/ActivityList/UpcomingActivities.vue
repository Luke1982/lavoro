<template>
    <BoxComponent>
        <div class="flex mb-4 border-b-1 border-gray-200 pb-2 justify-between">
            <div class="flex">
                <CalendarDateRangeIcon class="size-6 flex-none text-gray-500 mr-2" />
                <h2 class="font-regular text-xl">Aankomende activiteiten</h2>
            </div>
            <ComboBox
                :options="[{ id: '60', name: 'Aankomende 60 dagen' }, { id: '90', name: 'Aankomende 90 dagen' }, { id: '180', name: 'Aankomende 180 dagen' }, { id: '365', name: 'Aankomende 365 dagen' }]"
                class="w-64" placeholder="Filter op periode" v-model="form.days" :initial-id="form.days" />
        </div>
        <div v-for="mainAsset in upcomingAssets" :key="`mainAsset${mainAsset.id}`" class="my-8">
            <CustomerHeaderComponent :customer="mainAsset.customer" layout="horizontal" />
            <div
                class="grid grid-cols-12 text-sm bg-gray-200 p-2 rounded-tl-md rounded-tr-md mt-3 border border-gray-300 border-b-0">
                <div class="col-span-1">
                    <input type="checkbox" :checked="customerState(mainAsset.customer.id).all"
                        v-indeterminate="customerState(mainAsset.customer.id).some && !customerState(mainAsset.customer.id).all"
                        @change="selectAllFor(mainAsset.customer.id)" class="cursor-pointer" />
                </div>
                <div class="col-span-3">
                    Merk en model
                </div>
                <div class="col-span-1">
                    Serienummer
                </div>
                <div class="col-span-1">
                    Verloopdatum
                </div>
                <div class="col-span-2">
                    Soort product
                </div>
                <div class="col-span-4">
                    Storingen
                </div>
            </div>
            <div v-for="asset in mainAsset.customer.upcoming_assets" :key="asset.id"
                class="grid grid-cols-12 even:bg-gray-50 px-2 py-1 last:rounded-bl-md last:rounded-br-md border-l border-gray-200 border-r last:border-b">
                <div class="col-span-1">
                    <input type="checkbox" :id="`assetcheckbox-${asset.id}`" v-model="form.selectedAssets"
                        :value="{ id: asset.id, customer_id: mainAsset.customer.id }" class="cursor-pointer">
                </div>
                <div class="col-span-3 pr-5">
                    <label :for="`assetcheckbox-${asset.id}`" class="cursor-pointer">
                        {{ asset.product.brand.name }} {{ asset.product.model }}
                    </label>
                </div>
                <div class="col-span-1">
                    <Link :href="`/assets/${asset.id}`" class="cursor-pointer underline">
                    {{ asset.serial_number }}
                    </Link>
                </div>
                <div class="col-span-1">
                    <label :for="`assetcheckbox-${asset.id}`" class="cursor-pointer">
                        {{ nlDate(asset.next_service_date) }}
                    </label>
                </div>
                <div class="col-span-2">
                    {{ asset.product.product_type.name }}
                </div>
                <div class="col-span-4">
                    <div v-if="getNonPlannedTickets(asset.pending_tickets).length > 0">
                        <span class="text-xs font-bold">Lopende storingen</span>
                        <div v-for="ticket in asset.pending_tickets" :key="ticket.id" class="relative">
                            <TicketCard :ticket="ticket" v-if="ticket.service_order_id === null" class="mt-1 mb-1"
                                :modes="['simple', 'nodelete']" />
                            <input type="checkbox" :id="`ticketcheckbox-${ticket.id}`" v-model="form.selectedTickets"
                                :value="{ id: ticket.id, customer_id: mainAsset.customer.id }"
                                class="absolute top-2 left-2 size-5 cursor-pointer hidden">
                            <label :for="`ticketcheckbox-${ticket.id}`"
                                class="absolute left-0 top-0 w-full h-full cursor-pointer">
                                <CheckIcon v-if="form.selectedTickets.find(t => t.id === ticket.id)"
                                    class="size-6 ml-1 mt-1 text-blue-500"
                                    v-tooltip="'Deze storing is geselecteerd voor de werkbon'" />
                            </label>
                        </div>
                    </div>
                    <div v-if="getPlannedTickets(asset.pending_tickets).length > 0">
                        <span class="text-xs font-bold">Geplande lopende storingen</span>
                        <div v-for="ticket in getPlannedTickets(asset.pending_tickets)" :key="ticket.id">
                            Storing {{ ticket.id }} is gepland op
                            <Link class="underline" :href="`/serviceorders/${ticket.service_order_id}`">werkbon {{
                                ticket.service_order_id }}</Link>
                        </div>
                    </div>
                    <div v-if="getNonPlannedTickets(asset.open_tickets).length > 0">
                        <span class="text-xs font-bold">Openstaande storingen</span>
                        <div v-for="ticket in asset.open_tickets" :key="ticket.id" class="relative">
                            <TicketCard :ticket="ticket" v-if="ticket.service_order_id === null" class="mt-1 mb-1"
                                :modes="['simple', 'nodelete']" />
                            <input type="checkbox" :id="`ticketcheckbox-${ticket.id}`" v-model="form.selectedTickets"
                                :value="{ id: ticket.id, customer_id: mainAsset.customer.id }"
                                class="absolute top-2 left-2 size-5 cursor-pointer hidden">
                            <label :for="`ticketcheckbox-${ticket.id}`"
                                class="absolute left-0 top-0 w-full h-full cursor-pointer">
                                <CheckIcon v-if="form.selectedTickets.find(t => t.id === ticket.id)"
                                    class="size-6 ml-1 mt-1 text-blue-500"
                                    v-tooltip="'Deze storing is geselecteerd voor de werkbon'" />
                            </label>
                        </div>
                    </div>
                    <div v-if="getPlannedTickets(asset.open_tickets).length > 0">
                        <span class="text-xs font-bold">Geplande open storingen</span>
                        <div v-for="ticket in getPlannedTickets(asset.open_tickets)" :key="ticket.id">
                            Storing {{ ticket.id }} is gepland op
                            <Link class="underline" :href="`/serviceorders/${ticket.service_order_id}`">werkbon {{
                                ticket.service_order_id }}</Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </BoxComponent>
    <div v-auto-animate>
        <button v-if="canCreateWorkOrder !== 'no'" :disabled="canCreateWorkOrder === 'diffCustomers'"
            @click="createServiceOrder" v-auto-animate
            class="cursor-pointer fixed right-4 bottom-4 bg-amber-700 text-white p-4 rounded-md disabled:bg-red-600 disabled:cursor-not-allowed">
            <ClipboardDocumentCheckIcon class="w-6 h-6 inline-block mr-2" />
            <span v-if="canCreateWorkOrder === 'yes'">Maak een werkbon aan</span>
            <span v-else-if="canCreateWorkOrder === 'diffCustomers'">Selecteer storingen en keuringen van één
                klant</span>
        </button>
    </div>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import CustomerHeaderComponent from '@/Components/CustomerHeaderComponent.vue';
import TicketCard from '@/Components/TicketCard.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import { nlDate } from '@/Utilities/Utilities';
import { CalendarDateRangeIcon, CheckIcon, ClipboardDocumentCheckIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';
import { watch, ref } from 'vue';

const { upcomingAssets } = defineProps({
    upcomingAssets: {
        type: Array,
        required: true
    },
});

const form = useForm({
    days: new URL(window.location).searchParams.get('days') ?? '60',
    selectedAssets: [],
    selectedTickets: [],
});

const canCreateWorkOrder = ref('no');

watch(() => form.days, (newValue) => {
    form.get('/upcomingactivities', { days: newValue }, { preserveScroll: true });
});

watch(
    [() => form.selectedAssets, () => form.selectedTickets],
    ([newAssets, newTickets]) => {
        if (newAssets.length > 0 || newTickets.length > 0) {
            const uniqueCustomers = new Set();
            newAssets.forEach(asset => uniqueCustomers.add(asset.customer_id));
            newTickets.forEach(ticket => uniqueCustomers.add(ticket.customer_id));
            if (uniqueCustomers.size > 1) {
                canCreateWorkOrder.value = 'diffCustomers';
                return;
            }
            canCreateWorkOrder.value = 'yes';
        } else {
            canCreateWorkOrder.value = 'no';
        }
    },
    { deep: true }
);

const createServiceOrder = () => {
    if (canCreateWorkOrder.value !== 'yes') return;
    form.transform(data => {
        return {
            ...data,
            customer_id: data.selectedTickets.length > 0
                ? data.selectedTickets[0].customer_id
                : data.selectedAssets[0].customer_id,
            tickets: data.selectedTickets.map(ticket => ticket.id),
            assets: data.selectedAssets.map(asset => asset.id),
        };
    }).post('/serviceorders', { preserveScroll: true });
};

const getNonPlannedTickets = tickets => {
    return tickets.filter(ticket => ticket.service_order_id === null);
};
const getPlannedTickets = tickets => {
    return tickets.filter(ticket => ticket.service_order_id !== null);
};

const selectAllFor = (customerId) => {
    const main = upcomingAssets.find(a => a.customer && a.customer.id === customerId);
    if (!main) {
        form.selectedAssets = [];
        form.selectedTickets = [];
        return;
    }

    const assets = (main.customer.upcoming_assets || []);
    const assetIds = assets.map(a => a.id);

    const ticketIds = [];
    assets.forEach(asset => {
        (asset.pending_tickets || []).forEach(t => { if (t.service_order_id === null) ticketIds.push(t.id); });
        (asset.open_tickets || []).forEach(t => { if (t.service_order_id === null) ticketIds.push(t.id); });
    });

    const selectedAssetIds = new Set(form.selectedAssets.filter(a => a.customer_id === customerId).map(a => a.id));
    const selectedTicketIds = new Set(form.selectedTickets.filter(t => t.customer_id === customerId).map(t => t.id));
    const allSelectedForCustomer = selectedAssetIds.size === assetIds.length && selectedTicketIds.size === ticketIds.length;

    if (allSelectedForCustomer) {
        form.selectedAssets = form.selectedAssets.filter(a => a.customer_id !== customerId);
        form.selectedTickets = form.selectedTickets.filter(t => t.customer_id !== customerId);
        return;
    }

    form.selectedAssets = assetIds.map(id => ({ id, customer_id: customerId }));
    form.selectedTickets = ticketIds.map(id => ({ id, customer_id: customerId }));
};

const vIndeterminate = {
    mounted(el, { value }) { el.indeterminate = !!value },
    updated(el, { value }) { el.indeterminate = !!value },
};

const customerState = (customerId) => {
    const main = upcomingAssets.find(m => m.customer && m.customer.id === customerId);
    const assets = main?.customer?.upcoming_assets ?? [];

    const totalAssets = assets.length;
    let totalTickets = 0;
    assets.forEach(a => {
        (a.pending_tickets ?? []).forEach(t => { if (t.service_order_id === null) totalTickets++; });
        (a.open_tickets ?? []).forEach(t => { if (t.service_order_id === null) totalTickets++; });
    });
    const total = totalAssets + totalTickets;
    if (total === 0) return { all: false, some: false };

    const selA = form.selectedAssets.filter(a => a.customer_id === customerId).length;
    const selT = form.selectedTickets.filter(t => t.customer_id === customerId).length;
    const selected = selA + selT;

    return { all: selected === total, some: selected > 0 && selected < total };
};

</script>
