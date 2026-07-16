<template>
    <BoxComponent>
        <div class="sticky top-16 lg:top-0 z-2 bg-white">
            <CustomerHeaderComponent :customer="mainAsset.customer" layout="horizontal" class="" :counters="{
                assets: mainAsset.customer.upcoming_assets.length,
                openTickets: mainAsset.customer.upcoming_assets.reduce((sum, asset) => sum + asset.open_tickets.length, 0),
                pendingTickets: mainAsset.customer.upcoming_assets.reduce((sum, asset) => sum + asset.pending_tickets.length, 0)
            }" :has-expired="isExpired" />
            <div v-if="isExpired" class="absolute right-2 top-2 text-red-700"
                v-tooltip="'Deze klant heeft minimaal één machine die al verlopen is'">
                <ClockAlert />
            </div>
        </div>
        <div
            class="grid grid-cols-12 text-xs bg-lavoro-gray-150 -ml-6 -mr-6 px-6 py-3 text-gray-400 uppercase border-t-1 border-gray-200 border-b-1 mb-3">
            <div class="col-span-1">
                <input type="checkbox" :checked="customerState(mainAsset.customer.id).all"
                    v-indeterminate="customerState(mainAsset.customer.id).some && !customerState(mainAsset.customer.id).all"
                    @change="$emit('selectAll', mainAsset.customer.id)"
                    class="cursor-pointer size-4 accent-lavoro-blue dark:accent-lavoro-blue" />
            </div>
            <div class="hidden xl:block col-span-3">Merk en model</div>
            <div class="hidden xl:block col-span-2">Type en Serienummer</div>
            <div class="hidden xl:block col-span-2">Verloopdatum</div>
            <div class="hidden xl:block col-span-3">Storingen</div>
            <div class="col-span-11 xl:hidden text-gray-600 dark:text-slate-400">Activa en storingen, klik links om
                alles van deze klant te selecteren</div>
        </div>
        <template v-for="group in locationGroups" :key="group.location ? `loc-${group.location.id}` : 'no-loc'">
            <div class="flex items-center gap-2 mt-4 mb-3 pb-1 border-b border-gray-200 dark:border-slate-700">
                <MapPin class="size-4 text-lavoro-blue shrink-0" />
                <span class="font-semibold text-sm text-gray-700 dark:text-slate-200">
                    {{ group.location ? group.location.title : 'Geen locatie' }}
                </span>
                <span v-if="group.location" class="text-xs text-gray-400 dark:text-slate-500 truncate">
                    {{ [group.location.address, group.location.city].filter(Boolean).join(', ') }}
                </span>
            </div>
            <div v-for="asset in group.assets" :key="asset.id" class="grid grid-cols-12 mb-7">
            <div class="flex col-span-1">
                <input type="checkbox" :id="`assetcheckbox-${asset.id}`" :checked="isAssetSelected(asset.id)"
                    @change="toggleAssetSelection({ id: asset.id, customer_id: mainAsset.customer.id })"
                    class="cursor-pointer size-4 accent-lavoro-blue dark:accent-lavoro-blue">
                <div
                    class="w-20 h-20 p-1 rounded-sm border-lavoro-lightgray border-1 items-center justify-center ml-2 hidden sm:flex">
                    <img :src="asset.product.main_image?.[0] ? `/storage/${asset.product.main_image[0].path}` : '/img/placeholder.png'"
                        alt="">
                </div>
            </div>
            <div class="col-span-11 xl:col-span-3 pr-5">
                <label :for="`assetcheckbox-${asset.id}`" class="cursor-pointer dark:text-slate-100">
                    <div class="flex">
                        <div class="w-20 min-w-15 justify-center items-start mr-2 flex sm:hidden">
                            <img :src="asset.product.main_image?.[0] ? `/storage/${asset.product.main_image[0].path}` : '/img/placeholder.png'"
                                alt="">
                        </div>
                        <div class="flex flex-col">
                            <span class="text-sm">{{ asset.product.brand.name }} {{ asset.product.model }}
                            </span>
                            <!-- Mobile -->
                            <div class="text-xs block sm:hidden">
                                <span class="inline text-gray-600 font-bold">{{ asset.product.product_type.name }}
                                    met s/n </span>
                                <Link :href="`/assets/${asset.id}`"
                                    class="cursor-pointer underline text-lavoro-blue dark:text-lavoro-blue hover:dark:text-lavoro-lightblue inline">
                                    {{ asset.serial_number }}</Link>,
                                <span> verloopt op {{
                                    nlDate(asset.next_service_date)
                                    }}</span>
                            </div>
                        </div>
                    </div>
                </label>
                <div v-if="asset.pending_service_jobs.length > 0 || asset.has_past_planned_event">
                    <span class="text-xs text-gray-600 dark:text-slate-400">Er zijn nog openstaande keuringen voor
                        deze machine:</span>
                    <ul>
                        <li v-for="job in asset.pending_service_jobs" :key="job.id" class="py-1">
                            <BadgeComponent color="orange" :url="`/servicejobs/${job.id}`"
                                :tooltip="`Ga direct naar keuring ${job.id}`"> Keuring {{ job.id }}</BadgeComponent>
                            <span class="text-xs dark:text-slate-300 mx-1">op</span>
                            <BadgeComponent
                                :text="`Werkbon ${job.service_order.id} ${job.service_order?.coming_events?.length > 0 ? ' gepland op ' + nlDate(job.service_order.coming_events[0]?.start) : ''}`"
                                color="blue" :url="`/serviceorders/${job.service_order.id}`"
                                :tooltip="`Ga direct naar werkbon ${job.service_order.id}`">
                                <Calendar1 class="inline-block size-4 mr-1" aria-hidden="true" />
                                Werkbon {{ job.service_order.id }}
                                {{ job.service_order?.coming_events?.length > 0 ? ' gepland op ' +
                                    nlDate(job.service_order.coming_events[0]?.start) : '' }}
                            </BadgeComponent>
                        </li>
                    </ul>
                    <template v-if="asset.has_past_planned_event">
                        <span class="text-xs text-gray-600 dark:text-slate-400 block mt-1">Eerder ingepland:</span>
                        <ul>
                            <li v-for="(ev, idx) in asset.earlier_planned_events" :key="idx" class="¨mt-1">
                                <BadgeComponent color="red" :url="planEventHref(ev)"
                                    :tooltip="'Deze machine had eerder een geplande afspraak op ' + nlDate(ev.start)">
                                    <Calendar1 class="inline-block size-4 mr-1" aria-hidden="true" />
                                    Werkbon {{ ev.service_order_id }} op {{ nlDate(ev.start) }}
                                </BadgeComponent>
                            </li>
                        </ul>
                    </template>
                </div>
            </div>
            <div class="col-span-1 xl:hidden"></div>
            <div class="col-span-3 xl:col-span-2 sm:flex flex-col mt-5 xl:mt-0 hidden">
                <span class="text-xs font-bold xl:hidden">Serienummer</span>
                <span class="inline text-sm">{{ asset.product.product_type.name }} met s/n</span>
                <Link :href="`/assets/${asset.id}`"
                    class="cursor-pointer underline text-lavoro-blue dark:text-lavoro-blue hover:dark:text-lavoro-lightblue inline">
                    {{ asset.serial_number }}</Link>
            </div>
            <div class="col-span-3 xl:col-span-2 sm:flex flex-col mt-5 xl:mt-0 hidden">
                <span class="text-xs font-bold xl:hidden">Verloopdatum</span>
                <label :for="`assetcheckbox-${asset.id}`" class="cursor-pointer text-gray-700 dark:text-slate-300">{{
                    nlDate(asset.next_service_date)
                }}</label>
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
                            class="underline">hier
                        </Link> om deze te verwijderen van die werkbon, zodat je hem hier
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
                            class="underline">hier
                        </Link> om deze te verwijderen van die werkbon, zodat je hem hier
                        kunt koppelen aan een nieuwe.
                    </div>
                </div>
            </div>
            </div>
        </template>
    </BoxComponent>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import CustomerHeaderComponent from '@/Components/CustomerHeaderComponent.vue';
import BadgeComponent from '@/Components/UI/BadgeComponent.vue';
import TicketSelectCard from '@/Components/TicketSelectCard.vue';
import { nlDate } from '@/Utilities/Utilities';
import BoxComponent from '@/Components/BoxComponent.vue';
import { Calendar1, ClockAlert, MapPin } from '@lucide/vue';

const props = defineProps({
    mainAsset: { type: Object, required: true },
    selectedAssets: { type: Array, default: () => [] },
    selectedTickets: { type: Array, default: () => [] },
    customerState: { type: Function, required: true },
    getNonPlannedTickets: { type: Function, required: true },
    getPlannedTickets: { type: Function, required: true },
    isExpired: { type: Boolean, default: false },
});

const emit = defineEmits(['update:selectedAssets', 'update:selectedTickets', 'selectAll']);

const assetById = computed(() => {
    const map = {};
    (props.mainAsset.customer.upcoming_assets ?? []).forEach(a => { map[a.id] = a; });
    return map;
});

const locationGroups = computed(() => {
    const groups = props.mainAsset.customer.location_groups;
    if (!groups || !groups.length) {
        return [{ location: null, assets: props.mainAsset.customer.upcoming_assets ?? [] }];
    }
    return groups.map(g => ({
        location: g.location,
        assets: (g.asset_ids ?? []).map(id => assetById.value[id]).filter(Boolean),
    }));
});

const vIndeterminate = {
    mounted(el, { value }) { el.indeterminate = !!value },
    updated(el, { value }) { el.indeterminate = !!value },
};

const isAssetSelected = (assetId) => {
    return props.selectedAssets.some(a => a.id === assetId);
};

const planEventHref = (ev) => {
    const date = (typeof ev.start === 'string' && ev.start.includes('T')) ? ev.start.split('T')[0] : ev.start;
    const params = new URLSearchParams({
        gotodate: date,
        highlightevent: ev.event_id ?? '',
        executing_user_ids: (ev.executing_user_ids || []).join(','),
    });
    return `/planner?${params.toString()}`;
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
