<template>
    <div class="relative w-full h-52">
        <div ref="mapContainer" class="w-full h-full" />
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

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const { address } = defineProps({
    address: { type: String, required: true }
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
        const res = await fetch(
            `https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(address)}&format=json&limit=1&countrycodes=nl`,
            { headers: { 'Accept-Language': 'nl' } }
        );
        const results = await res.json();

        if (!results.length) {
            loading.value = false;
            notFound.value = true;
            return;
        }

        const { lat, lon } = results[0];

        map = L.map(mapContainer.value, {
            zoomControl: true,
            attributionControl: false,
        }).setView([parseFloat(lat), parseFloat(lon)], 8);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
        }).addTo(map);

        L.marker([parseFloat(lat), parseFloat(lon)], { icon: markerIcon }).addTo(map);

        loading.value = false;
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
