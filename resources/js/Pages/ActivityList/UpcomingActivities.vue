<template>
    <BoxComponent>
        <div class="flex flex-wrap mb-4 border-b-1 border-gray-200 pb-2 justify-between">
            <div class="flex w-full lg:w-auto justify-center mb-2 lg:mb-0">
                <CalendarDateRangeIcon class="size-6 flex-none text-gray-500 mr-2" />
                <h2 class="font-regular text-xl">Aankomende activiteiten</h2>
            </div>
            <div class="flex gap-2 items-start w-full lg:w-auto">
                <ComboBox
                    :options="[{ id: '60', name: 'Aankomende 60 dagen' }, { id: '90', name: 'Aankomende 90 dagen' }, { id: '180', name: 'Aankomende 180 dagen' }, { id: '365', name: 'Aankomende 365 dagen' }]"
                    class="w-full lg:w-64 z-20" placeholder="Filter op periode" v-model="form.days"
                    :initial-id="form.days" />
                <button type="button" @click="openMap"
                    class="px-3 py-2 bg-indigo-600 text-white rounded text-xs font-semibold hover:bg-indigo-700">
                    Kaart
                </button>
            </div>
        </div>
        <div v-for="mainAsset in upcomingAssets" :key="`mainAsset${mainAsset.id}`"
            :id="`customer-section-${mainAsset.customer.id}`" class="my-8">
            <div class="sticky top-16 lg:top-0 z-2 bg-white">
                <CustomerHeaderComponent :customer="mainAsset.customer" layout="horizontal"
                    class="bg-white py-4 lg:py-2" />
            </div>
            <div
                class="grid grid-cols-12 text-sm bg-gray-200 p-2 rounded-tl-md rounded-tr-md mt-3 border border-gray-300 border-b-0">
                <div class="col-span-1">
                    <input type="checkbox" :checked="customerState(mainAsset.customer.id).all"
                        v-indeterminate="customerState(mainAsset.customer.id).some && !customerState(mainAsset.customer.id).all"
                        @change="selectAllFor(mainAsset.customer.id)" class="cursor-pointer size-4" />
                </div>
                <div class="hidden xl:block col-span-3">
                    Merk en model
                </div>
                <div class="hidden xl:block col-span-1">
                    Serienummer
                </div>
                <div class="hidden xl:block col-span-1">
                    Verloopdatum
                </div>
                <div class="hidden xl:block col-span-2">
                    Soort product
                </div>
                <div class="hidden xl:block col-span-4">
                    Storingen
                </div>
                <div class="col-span-11 xl:hidden">
                    Activa en storingen, klik links om alles van deze klant te selecteren
                </div>
            </div>
            <div v-for="asset in mainAsset.customer.upcoming_assets" :key="asset.id"
                class="grid grid-cols-12 even:bg-gray-50 dark:bg-gray-900 dark:text-white even:dark:bg-gray-800 px-2 py-1 last:rounded-bl-md last:rounded-br-md border-l border-gray-200 border-r last:border-b">
                <div class="col-span-1">
                    <input type="checkbox" :id="`assetcheckbox-${asset.id}`" v-model="form.selectedAssets"
                        :value="{ id: asset.id, customer_id: mainAsset.customer.id }" class="cursor-pointer size-4">
                </div>
                <div class="col-span-11 xl:col-span-3 pr-5">
                    <label :for="`assetcheckbox-${asset.id}`" class="cursor-pointer">
                        {{ asset.product.brand.name }} {{ asset.product.model }}
                    </label>
                    <div v-if="asset.pending_service_jobs.length > 0">
                        <span class="text-xs">Er zijn nog openstaande keuringen voor deze machine:</span>
                        <span v-for="job in asset.pending_service_jobs" :key="job.id">
                            <BadgeComponent :text="`Keuring ${job.id}`" color="orange" :url="`/servicejobs/${job.id}`"
                                :tooltip="`Ga direct naar keuring ${job.id}`" />
                            <span class="text-xs">op</span>
                            <BadgeComponent
                                :text="`Werkbon ${job.service_order.id} ${job.service_order.events.length > 0 ? ' gepland op ' + nlDate(job.service_order.events[0]?.start) : ''}`"
                                color="blue" :url="`/serviceorders/${job.service_order.id}`"
                                :tooltip="`Ga direct naar werkbon ${job.service_order.id}`" />
                        </span>
                    </div>
                </div>
                <div class="col-span-1 xl:hidden"></div>
                <div class="col-span-3 xl:col-span-1 flex flex-col mt-5 xl:mt-0">
                    <span class="text-xs font-bold xl:hidden">Serienummer</span>
                    <Link :href="`/assets/${asset.id}`" class="cursor-pointer underline">
                    {{ asset.serial_number }}
                    </Link>
                </div>
                <div class="col-span-3 xl:col-span-1 flex flex-col mt-5 xl:mt-0">
                    <span class="text-xs font-bold xl:hidden">Verloopdatum</span>
                    <label :for="`assetcheckbox-${asset.id}`" class="cursor-pointer">
                        {{ nlDate(asset.next_service_date) }}
                    </label>
                </div>
                <div class="col-span-5 xl:col-span-2 flex flex-col mt-5 xl:mt-0">
                    <span class="text-xs font-bold xl:hidden">Soort product</span>
                    {{ asset.product.product_type.name }}
                </div>
                <div class="col-span-1 xl:hidden"></div>
                <div class="col-span-11 xl:col-span-4 flex flex-col mt-5 xl:mt-0">
                    <div v-if="getNonPlannedTickets(asset.pending_tickets).length > 0">
                        <span class="text-xs font-bold">Lopende storingen</span>
                        <div v-for="ticket in getNonPlannedTickets(asset.pending_tickets)" :key="ticket.id">
                            <TicketSelectCard :ticket="ticket" :customer-id="mainAsset.customer.id"
                                v-model="form.selectedTickets" />
                        </div>
                    </div>
                    <div v-if="getPlannedTickets(asset.pending_tickets).length > 0">
                        <span class="text-xs font-bold">Geplande lopende storingen</span>
                        <div v-for="ticket in getPlannedTickets(asset.pending_tickets)" :key="ticket.id">
                            Storing {{ ticket.id }} is gepland op
                            <Link class="underline" :href="`/serviceorders/${ticket.service_order_id}`">werkbon {{
                                ticket.service_order_id }}</Link>, klik
                            <Link :href="`/serviceorders/${ticket.service_order_id}/tickets/${ticket.id}/detach`"
                                class="underline">hier</Link> om deze te verwijderen van die werkbon, zodat je hem hier
                            kunt koppelen aan een nieuwe.
                        </div>
                    </div>
                    <div v-if="getNonPlannedTickets(asset.open_tickets).length > 0">
                        <span class="text-xs font-bold">Openstaande storingen</span>
                        <div v-for="ticket in getNonPlannedTickets(asset.open_tickets)" :key="ticket.id">
                            <TicketSelectCard :ticket="ticket" :customer-id="mainAsset.customer.id"
                                v-model="form.selectedTickets" />
                        </div>
                    </div>
                    <div v-if="getPlannedTickets(asset.open_tickets).length > 0">
                        <span class="text-xs font-bold">Geplande open storingen</span>
                        <div v-for="ticket in getPlannedTickets(asset.open_tickets)" :key="ticket.id">
                            Storing {{ ticket.id }} is gepland op
                            <Link class="underline" :href="`/serviceorders/${ticket.service_order_id}`">werkbon {{
                                ticket.service_order_id }}</Link>, klik
                            <Link :href="`/serviceorders/${ticket.service_order_id}/tickets/${ticket.id}/detach`"
                                class="underline">hier</Link> om deze te verwijderen van die werkbon, zodat je hem hier
                            kunt koppelen aan een nieuwe.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </BoxComponent>
    <div class="fixed right-4 bottom-4 left-4 z-3">
        <div v-auto-animate class="flex gap-2 flex-col lg:flex-row justify-end">
            <button v-if="canCreateWorkOrder === 'yes'" :disabled="canCreateWorkOrder === 'diffCustomers'"
                @click="createServiceOrder(false)" v-auto-animate
                class="cursor-pointer  bg-amber-700 text-white p-4 rounded-md disabled:bg-red-600 disabled:cursor-not-allowed">
                <ClipboardDocumentCheckIcon class="w-6 h-6 inline-block mr-2" />
                <span>Maak een werkbon aan en blijf hier</span>
            </button>
            <button v-if="canCreateWorkOrder !== 'no'" :disabled="canCreateWorkOrder === 'diffCustomers'"
                @click="createServiceOrder(true)" v-auto-animate
                class="cursor-pointer bg-amber-700 text-white p-4 rounded-md disabled:bg-red-600 disabled:cursor-not-allowed">
                <ClipboardDocumentCheckIcon class="w-6 h-6 inline-block mr-2" />
                <span v-if="canCreateWorkOrder === 'yes'">Maak een werkbon aan en open die</span>
                <span v-else-if="canCreateWorkOrder === 'diffCustomers'">Selecteer storingen en keuringen van één
                    klant</span>
            </button>
        </div>
    </div>
</template>

<script setup>
import BoxComponent from '@/Components/BoxComponent.vue';
import CustomerHeaderComponent from '@/Components/CustomerHeaderComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import TicketSelectCard from '@/Components/TicketSelectCard.vue';
import { nlDate } from '@/Utilities/Utilities';
import { CalendarDateRangeIcon, ClipboardDocumentCheckIcon } from '@heroicons/vue/24/outline';
import { Link, useForm } from '@inertiajs/vue3';
import { watch, ref } from 'vue';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';

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

const createServiceOrder = (redirect) => {
    if (canCreateWorkOrder.value !== 'yes') return;
    form.transform(data => {
        return {
            ...data,
            customer_id: data.selectedTickets.length > 0
                ? data.selectedTickets[0].customer_id
                : data.selectedAssets[0].customer_id,
            tickets: data.selectedTickets.map(ticket => ticket.id),
            assets: data.selectedAssets.map(asset => asset.id),
            redirect
        };
    }).post('/serviceorders', {
        preserveScroll: true, onSuccess: () => {
            form.reset()
        }
    });
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

// expose scroll function for popup window
if (typeof window !== 'undefined') {
    const scrollFunc = (id) => {
        const el = document.getElementById(`customer-section-${id}`);
        if (!el) return;
        // identify scroll container (page) - prefer window, else nearest scrollable parent
        let container = window;
        let parent = el.parentElement;
        while (parent && parent !== document.body) {
            const style = getComputedStyle(parent);
            const overflowY = style.overflowY;
            if (/(auto|scroll)/.test(overflowY)) { container = parent; break; }
            parent = parent.parentElement;
        }
        const headerOffset = 0; // adjust if fixed header present
        if (container === window) {
            const rect = el.getBoundingClientRect();
            const currentTop = window.scrollY || document.documentElement.scrollTop;
            const targetY = currentTop + rect.top - headerOffset;
            window.scrollTo({ top: targetY, behavior: 'smooth' });
        } else {
            container.scrollTo({ top: el.offsetTop - headerOffset, behavior: 'smooth' });
        }
        window.focus();
    };
    window.scrollToCustomer = scrollFunc;
    window.addEventListener('message', (e) => {
        if (!e.data || e.data.type !== 'scrollToCustomer') return;
        scrollFunc(e.data.id);
    });
}

const openMap = () => {
    window.open('/upcomingactivities/map', 'customerMap', 'width=1200,height=800');
};

</script>
