import { computed, h, onMounted, ref, render } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import axios from 'axios';

/**
 * Renders a Leaflet map of customer locations, geocoding any customer that
 * doesn't have lat/lon yet (via /geocode) and persisting the result back
 * (via PATCH /customers/{id}/coords) so it doesn't need to be geocoded again.
 *
 * `items` must be customer-shaped: { id, name, address, postal_code, city, lat, lon, ...}.
 */
export function useCustomerMapMarkers({
    items,
    markerColor,
    popupComponent,
    onMarkerClick,
    mapElementId = 'map',
    center = [52.1, 5.29],
    zoom = 7,
    coordsUrl = (item) => `/customers/${item.id}/coords`,
}) {
    const map = ref(null);
    const added = new Set();
    const queue = ref([]);
    const failedGeocodes = ref([]);
    const totalToGeocode = ref(0);
    const geocodedCount = ref(0);
    const geocodingStatus = ref('idle'); // idle, processing, success, failed

    const progress = computed(() => {
        if (totalToGeocode.value === 0) return 0;
        return (geocodedCount.value / totalToGeocode.value) * 100;
    });

    const markerIcon = (color) => L.divIcon({
        className: 'custom-marker',
        html: `<span style="display:inline-block;width:14px;height:14px;background:${color};border:2px solid white;border-radius:50%;box-shadow:0 0 2px rgba(0,0,0,.4);"></span>`,
        iconSize: [14, 14],
        iconAnchor: [7, 7],
    });

    const addMarker = (item) => {
        if (item.lat == null || item.lon == null) {
            return;
        }
        if (added.has(item.id)) {
            return;
        }
        added.add(item.id);

        const div = document.createElement('div');
        render(h(popupComponent, { customer: item }), div);

        const marker = L.marker([item.lat, item.lon], { icon: markerIcon(markerColor(item)) })
            .addTo(map.value)
            .bindPopup(div);

        if (onMarkerClick) {
            marker.on('click', () => onMarkerClick(item));
        }
    };

    const finishGeocoding = () => {
        if (failedGeocodes.value.length > 0) {
            geocodingStatus.value = 'failed';
        } else {
            geocodingStatus.value = 'success';
            setTimeout(() => geocodingStatus.value = 'idle', 5000);
        }
    };

    const geocodeOne = async () => {
        if (!queue.value.length) {
            finishGeocoding();
            return;
        }
        geocodingStatus.value = 'processing';
        const item = queue.value.shift();
        const query = [item.address, item.postal_code, item.city, 'Netherlands'].filter(Boolean).join(' ');
        try {
            const { data } = await axios.get('/geocode', { params: { address: query } });
            if (data.found) {
                item.lat = data.lat;
                item.lon = data.lon;
                addMarker(item);
                await axios.patch(coordsUrl(item), { lat: item.lat, lon: item.lon });
            } else {
                console.warn('Geocode failed for customer:', item.name, `(ID: ${item.id})`);
                console.warn('Query sent:', query);
                failedGeocodes.value.push(item);
            }
        } catch (e) {
            console.error('Geocode request failed for customer:', item.name, `(ID: ${item.id})`, e);
            failedGeocodes.value.push(item);
        } finally {
            geocodedCount.value++;
            if (queue.value.length) {
                setTimeout(geocodeOne, 1100);
            } else {
                finishGeocoding();
            }
        }
    };

    onMounted(() => {
        map.value = L.map(mapElementId).setView(center, zoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>',
        }).addTo(map.value);

        items.forEach((item) => {
            if (item.lat != null && item.lon != null) {
                addMarker(item);
            } else if (item.address || item.postal_code || item.city) {
                queue.value.push(item);
            }
        });

        totalToGeocode.value = queue.value.length;
        geocodedCount.value = 0;

        if (queue.value.length) {
            geocodeOne();
        }
    });

    return {
        map,
        geocodingStatus,
        geocodedCount,
        totalToGeocode,
        failedGeocodes,
        progress,
    };
}
