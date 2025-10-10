<template>
    <div class="w-screen h-screen">
        <div id="map" class="w-full h-full"></div>
        <div v-if="geocodingStatus !== 'idle'"
            class="fixed top-4 right-4 px-4 py-3 rounded-lg shadow-lg z-[1000] max-w-sm transition-colors duration-500"
            :class="boxClasses" @click="geocodingStatus === 'failed' ? (showFailedList = !showFailedList) : null">

            <!-- Processing State -->
            <div v-if="geocodingStatus === 'processing'">
                <div class="flex items-center justify-between">
                    <span>Adressen zoeken...</span>
                    <span class="text-sm">{{ geocodedCount }} / {{ totalToGeocode }}</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5 mt-2">
                    <div class="bg-blue-600 h-2.5 rounded-full" :style="{ width: progress + '%' }"></div>
                </div>
            </div>

            <!-- Success State -->
            <div v-if="geocodingStatus === 'success'" class="flex items-center">
                <CheckCircleIcon class="h-8 w-8 mr-2" />
                <span>Alle {{ totalToGeocode }} adressen gevonden.</span>
            </div>

            <!-- Failed State -->
            <div v-if="geocodingStatus === 'failed'" class="cursor-pointer">
                <div class="flex items-center">
                    <ExclamationTriangleIcon class="h-8 w-8 mr-2" />
                    <span>
                        {{ customers.length - failedGeocodes.length }} van {{ customers.length }} adressen te zien op de
                        kaart, {{ failedGeocodes.length }} konden er niet worden gevonden.
                    </span>
                </div>
                <transition name="fade-height">
                    <div v-if="showFailedList" class="mt-2 pt-2 border-t"
                        :class="geocodingStatus === 'failed' ? 'border-red-300' : 'border-gray-300'">
                        <ul class="list-disc list-inside text-sm max-h-60 overflow-y-auto">
                            <li v-for="c in failedGeocodes" :key="c.id">{{ c.name }}</li>
                        </ul>
                    </div>
                </transition>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref, defineOptions, computed } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import axios from 'axios';
import EmptyLayout from '@/Layouts/EmptyLayout.vue';
import { ExclamationTriangleIcon, CheckCircleIcon } from '@heroicons/vue/24/outline';

defineOptions({ layout: EmptyLayout });

const { customers } = defineProps({
    customers: { type: Array, required: true }
});

const queue = ref([]);
const map = ref(null);
const added = new Set();
const failedGeocodes = ref([]);
const showFailedList = ref(false);
const totalToGeocode = ref(0);
const geocodedCount = ref(0);
const geocodingStatus = ref('idle'); // idle, processing, success, failed

const progress = computed(() => {
    if (totalToGeocode.value === 0) return 0;
    return (geocodedCount.value / totalToGeocode.value) * 100;
});

const boxClasses = computed(() => {
    switch (geocodingStatus.value) {
        case 'processing':
            return 'bg-gray-100 border border-gray-400 text-gray-700';
        case 'success':
            return 'bg-green-100 border border-green-400 text-green-700';
        case 'failed':
            return 'bg-red-100 border border-red-400 text-red-700';
        default:
            return '';
    }
});

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
    html: `<span style="display:inline-block;width:14px;height:14px;background:${color};border:2px solid white;border-radius:50%;box-shadow:0 0 2px rgba(0,0,0,.4);"></span>`,
    iconSize: [14, 14],
    iconAnchor: [7, 7]
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
    const m = L.marker([c.lat, c.lon], { icon: markerIcon(color) })
        .addTo(map.value)
        .bindPopup(popupHtml(c));
    m.on('click', () => {
        setTimeout(() => {
            if (window.opener && window.opener.scrollToCustomer) {
                try {
                    window.opener.scrollToCustomer(c.id);
                } catch (e) {
                    console.debug('scrollToCustomer failed', e);
                }
            } else if (window.opener) {
                try {
                    window.opener.postMessage({ type: 'scrollToCustomer', id: c.id }, '*');
                } catch (e) {
                    console.debug('postMessage scrollToCustomer failed', e);
                }
            }
        }, 50);
    });
};

const geocodeOne = async () => {
    if (!queue.value.length) {
        if (failedGeocodes.value.length > 0) {
            geocodingStatus.value = 'failed';
        } else {
            geocodingStatus.value = 'success';
            setTimeout(() => geocodingStatus.value = 'idle', 5000);
        }
        return;
    }
    geocodingStatus.value = 'processing';
    const c = queue.value.shift();
    const q = [c.address, c.postal_code, c.city, 'Netherlands'].filter(Boolean).join(' ');
    try {
        const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}&limit=1`);
        const json = await res.json();
        if (json && json.length > 0 && json[0].lat && json[0].lon) {
            c.lat = parseFloat(json[0].lat);
            c.lon = parseFloat(json[0].lon);
            addMarker(c);
            await axios.patch(`/customers/${c.id}/coords`, { lat: c.lat, lon: c.lon });
        } else {
            console.warn('Geocode failed for customer:', c.name, `(ID: ${c.id})`);
            console.warn('Query sent to Nominatim:', q);
            console.warn('Nominatim response:', json);
            failedGeocodes.value.push(c);
        }
    } catch (e) {
        console.error('Geocode request failed for customer:', c.name, `(ID: ${c.id})`, e);
        failedGeocodes.value.push(c);
    } finally {
        geocodedCount.value++;
        if (queue.value.length) {
            setTimeout(geocodeOne, 1100);
        } else {
            if (failedGeocodes.value.length > 0) {
                geocodingStatus.value = 'failed';
            } else {
                geocodingStatus.value = 'success';
                setTimeout(() => geocodingStatus.value = 'idle', 5000);
            }
        }
    }
};

onMounted(() => {
    map.value = L.map('map').setView([52.1, 5.29], 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>'
    }).addTo(map.value);

    customers.forEach(c => {
        if (c.lat != null && c.lon != null) {
            addMarker(c);
        } else {
            if (c.address || c.postal_code || c.city) {
                queue.value.push(c);
            }
        }
    });

    totalToGeocode.value = queue.value.length;
    geocodedCount.value = 0;

    if (queue.value.length) {
        geocodeOne();
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

.fade-height-enter-active,
.fade-height-leave-active {
    transition: all 0.3s ease-in-out;
    max-height: 20rem;
}

.fade-height-enter-from,
.fade-height-leave-to {
    opacity: 0;
    transform: translateY(-10px);
    max-height: 0;
}
</style>
