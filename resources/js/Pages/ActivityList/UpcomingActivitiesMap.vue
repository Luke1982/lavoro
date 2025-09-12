<template>
    <div class="w-screen h-screen">
        <div id="map" class="w-full h-full"></div>
    </div>
</template>

<script setup>
import { onMounted, ref, defineOptions } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import axios from 'axios';
import EmptyLayout from '@/Layouts/EmptyLayout.vue';

defineOptions({ layout: EmptyLayout });

const { customers } = defineProps({
    customers: { type: Array, required: true }
});

const queue = ref([]);
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
        return;
    }
    const c = queue.value.shift();
    const q = [c.address, c.postal_code, c.city, 'Netherlands'].filter(Boolean).join(' ');
    try {
        const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}&limit=1`);
        const json = await res.json();
        if (json[0]) {
            c.lat = parseFloat(json[0].lat);
            c.lon = parseFloat(json[0].lon);
            addMarker(c);
            await axios.patch(`/customers/${c.id}/coords`, { lat: c.lat, lon: c.lon });
        }
    } catch (e) {
        console.warn('Geocode failed for', c.id, e);
    } finally {
        if (queue.value.length) {
            setTimeout(geocodeOne, 1100);
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
</style>
