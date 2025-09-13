<template>
    <div class="p-6 space-y-6 bg-gray-50 min-h-screen">
        <div>
            <input type="text" placeholder="Zoek objecten, werkbonnen..."
                class="w-full max-w-xl rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm outline-none" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-xl bg-white border border-gray-200 p-4 shadow-sm">
                <div class="text-sm text-gray-500">Objecten</div>
                <div class="mt-1 flex items-baseline gap-2">
                    <div class="text-2xl font-semibold">{{ stats.assets?.toLocaleString?.() ?? stats.assets }}</div>
                    <div class="text-emerald-600 text-xs">+3,1%</div>
                </div>
            </div>
            <div class="rounded-xl bg-white border border-gray-200 p-4 shadow-sm">
                <div class="text-sm text-gray-500">Werkbonnen</div>
                <div class="mt-1 flex items-baseline gap-2">
                    <div class="text-2xl font-semibold">{{ stats.serviceOrders?.toLocaleString?.() ??
                        stats.serviceOrders }}</div>
                </div>
            </div>
            <div class="rounded-xl bg-white border border-gray-200 p-4 shadow-sm">
                <div class="text-sm text-gray-500">Keuringen</div>
                <div class="mt-1 flex items-baseline gap-2">
                    <div class="text-2xl font-semibold">{{ stats.serviceJobs?.toLocaleString?.() ?? stats.serviceJobs }}
                    </div>
                    <div class="text-emerald-600 text-xs">+2,2%</div>
                </div>
            </div>
            <div class="rounded-xl bg-white border border-gray-200 p-4 shadow-sm">
                <div class="text-sm text-gray-500">Tickets</div>
                <div class="mt-1 flex items-baseline gap-2">
                    <div class="text-2xl font-semibold">{{ stats.tickets?.toLocaleString?.() ?? stats.tickets }}</div>
                    <div class="text-red-600 text-xs">-47%</div>
                </div>
            </div>
        </div>

        <div class="rounded-xl bg-white border border-gray-200 p-4 shadow-sm">
            <div class="text-sm font-medium mb-3">Werkbonnen en Keuringen (6 maanden)</div>
            <div class="h-40">
                <svg :viewBox="`0 0 ${svgW} ${svgH}`" preserveAspectRatio="none" class="w-full h-full">
                    <defs>
                        <linearGradient id="g" x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.2" />
                            <stop offset="100%" stop-color="#3b82f6" stop-opacity="0" />
                        </linearGradient>
                    </defs>
                    <g>
                        <line v-for="t in yGrid" :key="`g-${t.y}`" :x1="padLeft" :x2="svgW - padRight" :y1="t.y"
                            :y2="t.y" stroke="#EEF2FF" />
                    </g>
                    <g>
                        <text v-for="t in yTicks" :key="`yt-${t.y}`" :x="padLeft - 10" :y="t.y + 5" text-anchor="end"
                            font-size="13" fill="#6B7280">{{ t.label }}</text>
                        <text v-for="t in xTicks" :key="`xt-${t.x}`" :x="t.x" :y="svgH - 6" :text-anchor="t.anchor"
                            font-size="13" fill="#6B7280">{{ t.label }}</text>
                    </g>
                    <path :d="areaPath" fill="url(#g)" />
                    <path :d="linePath" fill="none" stroke="#3b82f6" stroke-width="3" stroke-linecap="round" />
                </svg>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="rounded-xl bg-white border border-gray-200 p-4 shadow-sm">
                <div class="text-sm font-medium mb-2">Open Werkbonnen</div>
                <ul class="divide-y divide-gray-100">
                    <li v-for="o in openServiceOrders" :key="o.id" class="py-2 flex items-center justify-between">
                        <span class="text-sm">#{{ o.id }} bij {{ o.customer?.name || 'Onbekende klant' }}</span>
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                                :class="o.sent_to_customer ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600'">
                                {{ o.sent_to_customer ? 'Verzonden' : 'Niet verzonden' }}
                            </span>
                            <Link :href="`/serviceorders/${o.id}`"
                                class="px-3 py-1 text-xs rounded-lg border border-gray-200">Open</Link>
                        </div>
                    </li>
                    <li v-if="!openServiceOrders || openServiceOrders.length === 0" class="py-4 text-sm text-gray-500">
                        Geen open werkbonnen</li>
                </ul>
            </div>
            <div class="rounded-xl bg-white border border-gray-200 p-4 shadow-sm">
                <div class="text-sm font-medium mb-2">Aankomende Keuringen</div>
                <ul class="divide-y divide-gray-100">
                    <li v-for="j in upcomingJobs" :key="j.id" class="py-2 flex items-center justify-between">
                        <span class="text-sm">SJ-{{ j.id }}</span>
                        <span class="px-3 py-1 text-xs rounded-xl bg-gray-100">Gepland</span>
                    </li>
                    <li v-if="!upcomingJobs || upcomingJobs.length === 0" class="py-4 text-sm text-gray-500">Geen
                        geplande keuringen</li>
                </ul>
            </div>
        </div>

        <div class="rounded-xl bg-white border border-gray-200 p-4 shadow-sm">
            <div class="text-sm font-medium mb-2">Recente Tickets</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="py-2 pr-4">Nr</th>
                            <th class="py-2 pr-4">Onderwerp</th>
                            <th class="py-2 pr-4">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="t in recentTickets" :key="t.id">
                            <td class="py-2 pr-4">
                                <Link :href="`/tickets/${t.id}`" class="text-blue-600 hover:underline">T-{{ t.id }}
                                </Link>
                            </td>
                            <td class="py-2 pr-4">
                                <Link :href="`/tickets/${t.id}`" class="text-blue-600 hover:underline">{{ t.subject }}
                                </Link>
                            </td>
                            <td class="py-2 pr-4">{{ t.status }}</td>
                        </tr>
                        <tr v-if="!recentTickets || recentTickets.length === 0">
                            <td colspan="3" class="py-4 text-sm text-gray-500">Geen recente tickets</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl bg-white border border-gray-200 p-4 shadow-sm">
            <div class="text-sm font-medium mb-2">Kaart</div>
            <div class="h-48">
                <div id="dashboard-map" class="w-full h-full rounded-lg"></div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const { customers, stats, openServiceOrders, upcomingJobs, recentTickets } = defineProps({
    customers: { type: Array, required: true },
    stats: { type: Object, required: true },
    openServiceOrders: { type: Array, required: true },
    upcomingJobs: { type: Array, required: true },
    recentTickets: { type: Array, required: true }
});

const map = ref(null);
const added = new Set();
const linePath = ref('');
const areaPath = ref('');
const svgW = 1000;
const svgH = 220;
const padLeft = 48;
const padRight = 10;
const padTop = 10;
const padBottom = 36;
const yGrid = ref([]);
const yTicks = ref([]);
const xTicks = ref([]);

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
    const data = [20, 40, 25, 45, 42, 47, 65];
    const n = data.length;
    const dx = (svgW - padLeft - padRight) / (n - 1);
    const min = Math.min(...data);
    const max = Math.max(...data);
    const scaleY = (v) => {
        if (max === min) {
            return svgH / 2;
        }
        return svgH - padBottom - ((v - min) / (max - min)) * (svgH - padTop - padBottom);
    };
    let d = '';
    for (let i = 0; i < n; i += 1) {
        const x = padLeft + i * dx;
        const y = scaleY(data[i]);
        if (i === 0) {
            d += `M ${x} ${y}`;
        } else {
            d += ` L ${x} ${y}`;
        }
    }
    linePath.value = d;
    const endX = svgW - padRight;
    const baseY = svgH - padBottom;
    areaPath.value = `${d} L ${endX} ${baseY} L ${padLeft} ${baseY} Z`;
    const t0 = min;
    const t1 = min + (max - min) / 3;
    const t2 = min + 2 * (max - min) / 3;
    const t3 = max;
    yGrid.value = [t0, t1, t2, t3].map(v => ({ y: scaleY(v) }));
    yTicks.value = [t0, t1, t2, t3].map(v => ({ y: scaleY(v), label: Math.round(v) }));
    const ticks = [];
    for (let i = 0; i < n; i += 1) {
        const x = padLeft + i * dx;
        const remaining = (n - 1) - i;
        let label = '';
        if (remaining === 0) {
            label = 'Nu';
        } else {
            label = `-${remaining} mnd`;
        }
        const anchor = i === 0 ? 'start' : (i === n - 1 ? 'end' : 'middle');
        ticks.push({ x, label, anchor });
    }
    xTicks.value = ticks;
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
</script>

<style>
html,
body,
#app {
    margin: 0;
    padding: 0;
}
</style>