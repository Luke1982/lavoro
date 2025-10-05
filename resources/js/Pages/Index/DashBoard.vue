<template>
    <div class="p-6 space-y-6 bg-gray-50 dark:bg-slate-950 min-h-screen">


        <div v-if="hasPermission('dashboard.see_stats')" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div
                class="rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700/60 p-4 shadow-sm dark:shadow-none">
                <div class="text-sm text-gray-500 dark:text-slate-400">Objecten</div>
                <div class="mt-1 flex items-baseline gap-2">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-slate-100">{{
                        stats.assets?.toLocaleString?.() ?? stats.assets }}</div>
                    <div class="text-emerald-600 text-xs">+3,1%</div>
                </div>
            </div>
            <div
                class="rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700/60 p-4 shadow-sm dark:shadow-none">
                <div class="text-sm text-gray-500 dark:text-slate-400">Werkbonnen</div>
                <div class="mt-1 flex items-baseline gap-2">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-slate-100">{{
                        stats.serviceOrders?.toLocaleString?.() ??
                        stats.serviceOrders }}</div>
                </div>
            </div>
            <div
                class="rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700/60 p-4 shadow-sm dark:shadow-none">
                <div class="text-sm text-gray-500 dark:text-slate-400">Keuringen</div>
                <div class="mt-1 flex items-baseline gap-2">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-slate-100">{{
                        stats.serviceJobs?.toLocaleString?.() ?? stats.serviceJobs }}
                    </div>
                    <div class="text-emerald-600 text-xs">+2,2%</div>
                </div>
            </div>
            <div
                class="rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700/60 p-4 shadow-sm dark:shadow-none">
                <div class="text-sm text-gray-500 dark:text-slate-400">Tickets</div>
                <div class="mt-1 flex items-baseline gap-2">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-slate-100">{{
                        stats.tickets?.toLocaleString?.() ?? stats.tickets }}</div>
                    <div class="text-red-600 text-xs">-47%</div>
                </div>
            </div>
        </div>

        <div v-if="hasPermission('dashboard.see_events')"
            class="rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700/60 p-4 shadow-sm dark:shadow-none">
            <div class="text-sm font-medium mb-2 text-gray-900 dark:text-slate-100">Afspraken</div>
            <CalendarWidget :allCustomers="customers" height="60vh" read-only />
        </div>



        <div v-if="canSeeOpenOrders" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div
                class="rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700/60 p-4 shadow-sm dark:shadow-none">
                <div class="text-sm font-medium mb-2 text-gray-900 dark:text-slate-100">Open Werkbonnen</div>
                <div class="flex items-center gap-2 mb-3">
                    <button v-if="canNotSent" class="px-2 py-1 text-xs rounded border"
                        :class="ordersFilter === 'neither' ? 'bg-gray-900 text-white border-gray-900 dark:bg-slate-100 dark:text-slate-900 dark:border-slate-100' : 'bg-white border-gray-300 dark:bg-slate-900 dark:border-slate-700/60 dark:text-slate-100'"
                        @click="ordersFilter = 'neither'">Niet verzonden</button>
                    <button v-if="canAdministration" class="px-2 py-1 text-xs rounded border"
                        :class="ordersFilter === 'administration' ? 'bg-gray-900 text-white border-gray-900 dark:bg-slate-100 dark:text-slate-900 dark:border-slate-100' : 'bg-white border-gray-300 dark:bg-slate-900 dark:border-slate-700/60 dark:text-slate-100'"
                        @click="ordersFilter = 'administration'">Alleen administratie</button>
                    <button v-if="canCustomer" class="px-2 py-1 text-xs rounded border"
                        :class="ordersFilter === 'customer' ? 'bg-gray-900 text-white border-gray-900 dark:bg-slate-100 dark:text-slate-900 dark:border-slate-100' : 'bg-white border-gray-300 dark:bg-slate-900 dark:border-slate-700/60 dark:text-slate-100'"
                        @click="ordersFilter = 'customer'">Alleen klant</button>
                    <div class="ml-auto">
                        <button class="text-xs underline text-gray-700 dark:text-slate-300"
                            @click="showAllOpenOrders = !showAllOpenOrders">
                            {{ showAllOpenOrders ? 'Toon minder' : 'Toon alles' }}
                        </button>
                    </div>
                </div>
                <ul class="divide-y divide-gray-100 dark:divide-slate-800" v-auto-animate>
                    <li v-for="o in openOrdersToShow" :key="o.id" class="py-2 flex items-center justify-between">
                        <span class="text-sm text-gray-800 dark:text-slate-200">#{{ o.id }} bij {{ o.customer?.name ||
                            'Onbekende klant' }}</span>
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium border"
                                :class="serviceOrderPillColorClasses(o)">
                                {{ serviceOrderPillText(o) }}
                            </span>
                            <Link :href="`/serviceorders/${o.id}`"
                                class="px-3 py-1 text-xs rounded-lg border border-gray-200 dark:border-slate-700/60 dark:text-slate-100">
                            Open</Link>
                        </div>
                    </li>
                    <li v-if="filteredOpenOrders.length === 0" class="py-4 text-sm text-gray-500 dark:text-slate-400">
                        Geen werkbonnen
                    </li>
                </ul>
            </div>
            <div v-if="hasPermission('dashboard.see_upcoming_servicejobs')"
                class="rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700/60 p-4 shadow-sm dark:shadow-none">
                <div class="text-sm font-medium mb-2 text-gray-900 dark:text-slate-100">Aankomende Keuringen</div>
                <ul class="divide-y divide-gray-100 dark:divide-slate-800">
                    <li v-for="j in upcomingJobs" :key="j.id" class="py-2 flex items-center justify-between">
                        <span class="text-sm text-gray-800 dark:text-slate-200">SJ-{{ j.id }}</span>
                        <span
                            class="px-3 py-1 text-xs rounded-xl bg-gray-100 dark:bg-slate-800 dark:text-slate-200">Gepland</span>
                    </li>
                    <li v-if="!upcomingJobs || upcomingJobs.length === 0"
                        class="py-4 text-sm text-gray-500 dark:text-slate-400">Geen
                        geplande keuringen</li>
                </ul>
            </div>
        </div>

        <div v-if="hasPermission('dashboard.see_pending_tickets')"
            class="rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700/60 p-4 shadow-sm dark:shadow-none">
            <div class="text-sm font-medium mb-2 text-gray-900 dark:text-slate-100">Recente Tickets</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-slate-400">
                            <th class="py-2 pr-4">Nr</th>
                            <th class="py-2 pr-4">Onderwerp</th>
                            <th class="py-2 pr-4">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-800">
                        <tr v-for="t in recentTickets" :key="t.id">
                            <td class="py-2 pr-4">
                                <Link :href="`/tickets/${t.id}`"
                                    class="text-blue-600 dark:text-blue-400 hover:underline">T-{{ t.id }}
                                </Link>
                            </td>
                            <td class="py-2 pr-4">
                                <Link :href="`/tickets/${t.id}`"
                                    class="text-blue-600 dark:text-blue-400 hover:underline">{{ t.subject }}
                                </Link>
                            </td>
                            <td class="py-2 pr-4 text-gray-800 dark:text-slate-200">{{ t.status }}</td>
                        </tr>
                        <tr v-if="!recentTickets || recentTickets.length === 0">
                            <td colspan="3" class="py-4 text-sm text-gray-500 dark:text-slate-400">Geen recente tickets
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="hasPermission('dashboard.see_map')"
            class="rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700/60 p-4 shadow-sm dark:shadow-none">
            <div class="text-sm font-medium mb-2 text-gray-900 dark:text-slate-100">Kaart</div>
            <div class="h-[36rem]">
                <div id="dashboard-map" class="w-full h-full rounded-lg"></div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { serviceOrderPillText, serviceOrderPillColorClasses, hasPermission } from '@/Utilities/Utilities';
import CalendarWidget from '@/Components/CalendarWidget.vue'
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const { customers, stats, openServiceOrders, upcomingJobs, recentTickets } = defineProps({
    customers: { type: Array, required: true },
    stats: { type: Object, required: true },
    openServiceOrders: { type: Array, required: true },
    upcomingJobs: { type: Array, required: true },
    recentTickets: { type: Array, required: true }
});

const ordersFilter = ref('neither');
const showAllOpenOrders = ref(false);

const canNotSent = computed(() => hasPermission('dashboard.see_open_serviceorders.not_sent'))
const canAdministration = computed(() => hasPermission('dashboard.see_open_serviceorders.sent_administration'))
const canCustomer = computed(() => hasPermission('dashboard.see_open_serviceorders.sent_customer'))
const canAllOpen = computed(() => hasPermission('dashboard.see_open_serviceorders.all'))
const canSeeOpenOrders = computed(() => canNotSent.value || canAdministration.value || canCustomer.value || canAllOpen.value)

const allClosedOrdersNeedingSend = computed(() => {
    const list = (openServiceOrders || []).slice();
    return list.sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at));
});

const filteredOpenOrders = computed(() => {
    if (ordersFilter.value === 'neither') {
        return allClosedOrdersNeedingSend.value.filter(o => !o.sent_to_administration && !o.sent_to_customer);
    }
    if (ordersFilter.value === 'administration') {
        return allClosedOrdersNeedingSend.value.filter(o => o.sent_to_administration && !o.sent_to_customer);
    }
    if (ordersFilter.value === 'customer') {
        return allClosedOrdersNeedingSend.value.filter(o => !o.sent_to_administration && o.sent_to_customer);
    }
    return allClosedOrdersNeedingSend.value;
});

const openOrdersToShow = computed(() => showAllOpenOrders.value
    ? filteredOpenOrders.value
    : filteredOpenOrders.value.slice(0, 5));


const map = ref(null);
const added = new Set();

const classifyColor = (days) => {
    if (days === null || days === undefined) {
        return 'gray';
    }
    if (days <= 30) {
        return 'red';
    }
    if (days <= 60) {
        return 'orange';
    }
    return 'green';
};

const markerIcon = (color) => L.divIcon({
    className: 'custom-marker',
    html: `<span style="display:inline-block;width:12px;height:12px;background:${color};border:2px solid white;border-radius:50%;box-shadow:0 0 2px rgba(0,0,0,.4);"></span>`,
    iconSize: [12, 12],
    iconAnchor: [6, 6]
});

const popupHtml = (c) => {
    const dagen = c.next_service_in_days != null ? Math.max(0, Math.round(c.next_service_in_days)) : null;
    let line = '';
    if (dagen != null && c.earliest_asset_product_type) {
        line = `Eerstvolgende keuring over ${dagen} dagen aan ${c.earliest_asset_product_type}`;
        if (c.earliest_asset_serial) {
            line += ` (${c.earliest_asset_serial})`;
        }
    } else if (dagen != null) {
        line = `Eerstvolgende keuring over ${dagen} dagen`;
    } else {
        line = 'Geen geplande keuring bekend';
    }
    return `<strong>${c.name}</strong><br>${[c.address, c.postal_code, c.city].filter(Boolean).join(' ')}<br>${line}`;
};

const addMarker = (c) => {
    if (c.lat == null || c.lon == null) {
        return;
    }
    if (added.has(c.id)) {
        return;
    }
    added.add(c.id);
    const color = classifyColor(c.next_service_in_days);
    L.marker([c.lat, c.lon], { icon: markerIcon(color) })
        .addTo(map.value)
        .bindPopup(popupHtml(c));
};

onMounted(() => {
    map.value = L.map('dashboard-map', { zoomControl: true }).setView([52.1, 5.29], 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>'
    }).addTo(map.value);

    const group = L.featureGroup();
    customers.forEach((c) => {
        if (c.lat != null && c.lon != null) {
            addMarker(c);
            const m = L.marker([c.lat, c.lon]);
            group.addLayer(m);
        }
    });
    if (group.getLayers().length > 0) {
        map.value.fitBounds(group.getBounds().pad(0.2));
    }
});

// Customer lookup handled inside CalendarWidget
</script>

<style>
html,
body,
#app {
    margin: 0;
    padding: 0;
}
</style>