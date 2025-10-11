<template>
    <div>
        <div
            class="sticky top-16 lg:top-0 z-2 bg-white dark:bg-slate-800 dark:text-slate-100 border border-transparent dark:border-slate-700 rounded-md px-4 py-2">
            <CustomerHeaderComponent :customer="mainAsset.customer" layout="horizontal"
                class="bg-white dark:bg-transparent py-4 lg:py-2" />
        </div>
        <div
            class="grid grid-cols-12 text-xs lg:text-sm font-medium bg-gray-200 dark:bg-slate-800 text-gray-700 dark:text-slate-300 p-2 rounded-tl-md rounded-tr-md mt-3 border border-gray-300 dark:border-slate-700 border-b-0 tracking-wide">
            <div class="col-span-1">
                <input type="checkbox" :checked="customerState(mainAsset.customer.id).all"
                    v-indeterminate="customerState(mainAsset.customer.id).some && !customerState(mainAsset.customer.id).all"
                    @change="$emit('selectAll', mainAsset.customer.id)"
                    class="cursor-pointer size-4 accent-indigo-600 dark:accent-indigo-500" />
            </div>
            <div class="hidden xl:block col-span-3">Merk en model</div>
            <div class="hidden xl:block col-span-1">Serienummer</div>
            <div class="hidden xl:block col-span-1">Verloopdatum</div>
            <div class="hidden xl:block col-span-2">Soort product</div>
            <div class="hidden xl:block col-span-4">Storingen</div>
            <div class="col-span-11 xl:hidden text-gray-600 dark:text-slate-400">Activa en storingen, klik links om
                alles van deze klant te selecteren</div>
        </div>
        <div v-for="asset in mainAsset.customer.upcoming_assets" :key="asset.id"
            class="grid grid-cols-12 px-2 py-2 lg:py-1 last:rounded-bl-md last:rounded-br-md border-l border-gray-200 dark:border-slate-700/60 border-r last:border-b dark:last:border-slate-700/60 bg-white even:bg-gray-50 dark:bg-slate-900 even:dark:bg-slate-800/70 hover:dark:bg-slate-700/70 transition-colors text-gray-800 dark:text-slate-200">
            <div class="col-span-1">
                <input type="checkbox" :id="`assetcheckbox-${asset.id}`" :checked="isAssetSelected(asset.id)"
                    @change="toggleAssetSelection({ id: asset.id, customer_id: mainAsset.customer.id })"
                    class="cursor-pointer size-4 accent-indigo-600 dark:accent-indigo-500">
            </div>
            <div class="col-span-11 xl:col-span-3 pr-5">
                <label :for="`assetcheckbox-${asset.id}`" class="cursor-pointer dark:text-slate-100">
                    {{ asset.product.brand.name }} {{ asset.product.model }}
                </label>
                <div v-if="asset.pending_service_jobs.length > 0">
                    <span class="text-xs text-gray-600 dark:text-slate-400">Er zijn nog openstaande keuringen voor
                        deze machine:</span>
                    <span v-for="job in asset.pending_service_jobs" :key="job.id">
                        <BadgeComponent :text="`Keuring ${job.id}`" color="orange" :url="`/servicejobs/${job.id}`"
                            :tooltip="`Ga direct naar keuring ${job.id}`" />
                        <span class="text-xs dark:text-slate-300">op</span>
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
                <Link :href="`/assets/${asset.id}`"
                    class="cursor-pointer underline text-indigo-700 dark:text-indigo-400 hover:dark:text-indigo-300">
                {{ asset.serial_number }}</Link>
            </div>
            <div class="col-span-3 xl:col-span-1 flex flex-col mt-5 xl:mt-0">
                <span class="text-xs font-bold xl:hidden">Verloopdatum</span>
                <label :for="`assetcheckbox-${asset.id}`" class="cursor-pointer text-gray-700 dark:text-slate-300">{{
                    nlDate(asset.next_service_date)
                    }}</label>
            </div>
            <div class="col-span-5 xl:col-span-2 flex flex-col mt-5 xl:mt-0">
                <span class="text-xs font-bold xl:hidden">Soort product</span>
                <span class="text-gray-700 dark:text-slate-300">{{ asset.product.product_type.name }}</span>
            </div>
            <div class="col-span-1 xl:hidden"></div>
            <div class="col-span-11 xl:col-span-4 flex flex-col mt-5 xl:mt-0">
                <div v-if="getNonPlannedTickets(asset.pending_tickets).length > 0">
                    <span class="text-xs font-medium text-gray-700 dark:text-slate-300 tracking-wide">Lopende
                        storingen</span>
                    <div v-for="ticket in getNonPlannedTickets(asset.pending_tickets)" :key="ticket.id">
                        <TicketSelectCard :ticket="ticket" :customer-id="mainAsset.customer.id"
                            :modelValue="selectedTickets"
                            @update:modelValue="$emit('update:selectedTickets', $event)" />
                    </div>
                </div>
                <div v-if="getPlannedTickets(asset.pending_tickets).length > 0">
                    <span class="text-xs font-medium text-gray-700 dark:text-slate-300 tracking-wide">Geplande
                        lopende storingen</span>
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
                    <span class="text-xs font-medium text-gray-700 dark:text-slate-300 tracking-wide">Openstaande
                        storingen</span>
                    <div v-for="ticket in getNonPlannedTickets(asset.open_tickets)" :key="ticket.id">
                        <TicketSelectCard :ticket="ticket" :customer-id="mainAsset.customer.id"
                            :modelValue="selectedTickets"
                            @update:modelValue="$emit('update:selectedTickets', $event)" />
                    </div>
                </div>
                <div v-if="getPlannedTickets(asset.open_tickets).length > 0">
                    <span class="text-xs font-medium text-gray-700 dark:text-slate-300 tracking-wide">Geplande open
                        storingen</span>
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
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import CustomerHeaderComponent from '@/Components/CustomerHeaderComponent.vue';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';
import TicketSelectCard from '@/Components/TicketSelectCard.vue';
import { nlDate } from '@/Utilities/Utilities';

const props = defineProps({
    mainAsset: { type: Object, required: true },
    selectedAssets: { type: Array, default: () => [] },
    selectedTickets: { type: Array, default: () => [] },
    customerState: { type: Function, required: true },
    getNonPlannedTickets: { type: Function, required: true },
    getPlannedTickets: { type: Function, required: true },
});

const emit = defineEmits(['update:selectedAssets', 'update:selectedTickets', 'selectAll']);

const vIndeterminate = {
    mounted(el, { value }) { el.indeterminate = !!value },
    updated(el, { value }) { el.indeterminate = !!value },
};

const isAssetSelected = (assetId) => {
    return props.selectedAssets.some(a => a.id === assetId);
};

const toggleAssetSelection = (asset) => {
    const newSelection = [...props.selectedAssets];
    const index = newSelection.findIndex(a => a.id === asset.id);
    if (index > -1) {
        newSelection.splice(index, 1);
    } else {
        newSelection.push(asset);
    }
    emit('update:selectedAssets', newSelection);
};
</script>
