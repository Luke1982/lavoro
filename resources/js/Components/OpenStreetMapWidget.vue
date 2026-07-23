<template>
    <div :class="['relative w-full', heightClass]">
        <div ref="mapContainer" class="w-full h-full" />
        <span v-if="sourceLabel && !loading && !notFound"
            class="absolute bottom-0 right-0 z-[650] rounded-tl-md bg-white/85 dark:bg-slate-900/85 px-1.5 py-0.5 text-[10px] font-medium text-gray-600 dark:text-slate-300">
            {{ sourceLabel }}
        </span>
        <Transition enter-active-class="transition-opacity duration-300" enter-from-class="opacity-100"
            leave-active-class="transition-opacity duration-300" leave-to-class="opacity-0">
            <div v-if="loading || notFound"
                class="absolute inset-0 flex items-center justify-center bg-gray-100 dark:bg-slate-800">
                <span class="text-sm text-gray-400 dark:text-slate-500">
                    {{ notFound ? 'Adres niet gevonden' : 'Kaart laden...' }}
                </span>
            </div>
        </Transition>
    </div>
</template>

<script>
import axios from 'axios';

// Shared across every instance: dedupes and caches geocode lookups so repeated
// addresses (and re-mounts on theme/key changes) never re-hit the endpoint.
const geocodeCache = new Map();

function geocodeAddress(query) {
    if (!geocodeCache.has(query)) {
        geocodeCache.set(
            query,
            axios.get('/geocode', { params: { address: query } })
                .then(response => response.data)
                .catch(error => {
                    geocodeCache.delete(query);
                    throw error;
                })
        );
    }
    return geocodeCache.get(query);
}
</script>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const { address, sourceLabel, heightClass, interactive } = defineProps({
    address: { type: String, required: true },
    sourceLabel: { type: String, default: '' },
    heightClass: { type: String, default: 'h-52' },
    interactive: { type: Boolean, default: true }
});

const mapContainer = ref(null);
const loading = ref(true);
const notFound = ref(false);
let map = null;

const markerIcon = L.divIcon({
    className: '',
    html: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 34" width="24" height="34">
        <path d="M12 1C7.03 1 3 5.03 3 10c0 7.25 9 23 9 23s9-15.75 9-23c0-4.97-4.03-9-9-9z"
              fill="#2563ff" stroke="white" stroke-width="1.5" stroke-linejoin="round"/>
        <circle cx="12" cy="10" r="3.5" fill="white"/>
    </svg>`,
    iconSize: [24, 34],
    iconAnchor: [12, 34],
});

onMounted(async () => {
    if (!address?.trim() || !mapContainer.value) {
        loading.value = false;
        notFound.value = true;
        return;
    }

    try {
        const data = await geocodeAddress(address);

        if (!data.found) {
            loading.value = false;
            notFound.value = true;
            return;
        }

        const { lat, lon } = data;

        map = L.map(mapContainer.value, {
            zoomControl: interactive,
            attributionControl: false,
            dragging: interactive,
            scrollWheelZoom: interactive,
            doubleClickZoom: interactive,
            boxZoom: interactive,
            keyboard: interactive,
            touchZoom: interactive,
        }).setView([parseFloat(lat), parseFloat(lon)], interactive ? 8 : 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
        }).addTo(map);

        L.marker([parseFloat(lat), parseFloat(lon)], { icon: markerIcon, interactive }).addTo(map);

        loading.value = false;

        setTimeout(() => map?.invalidateSize(), 150);
    } catch {
        loading.value = false;
        notFound.value = true;
    }
});

onUnmounted(() => {
    map?.remove();
    map = null;
});
</script>
