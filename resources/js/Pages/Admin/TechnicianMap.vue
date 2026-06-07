<template>
    <div class="relative" style="height: calc(100vh - 4rem)">
        <div ref="map_el" class="w-full h-full" />

        <div class="absolute top-4 left-4 z-[1000] bg-white dark:bg-slate-800 rounded-lg shadow-lg p-3 min-w-40">
            <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">Technici (laatste 8u)</p>
            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ props.pings.length }} zichtbaar</p>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const props = defineProps({
    pings: { type: Array, required: true },
});

const map_el = ref(null);
let map = null;

onMounted(() => {
    map = L.map(map_el.value).setView([52.3, 5.3], 8);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 18,
    }).addTo(map);

    props.pings.forEach((ping) => {
        const age_min = Math.round((Date.now() - new Date(ping.recorded_at).getTime()) / 60_000);

        L.circleMarker([ping.lat, ping.lng], {
            radius: 10,
            color: '#1d4ed8',
            fillColor: '#3b82f6',
            fillOpacity: 0.85,
            weight: 2,
        })
            .addTo(map)
            .bindPopup(`<strong>${ping.user?.name ?? 'Onbekend'}</strong><br>${age_min} min. geleden`);
    });

    if (props.pings.length > 0) {
        map.fitBounds(
            L.latLngBounds(props.pings.map((p) => [p.lat, p.lng])),
            { padding: [50, 50] }
        );
    }
});

onUnmounted(() => {
    map?.remove();
    map = null;
});
</script>
