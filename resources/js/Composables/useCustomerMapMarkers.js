import { computed, h, onMounted, ref, render, shallowRef } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import axios from 'axios';
import { clusterIcon, pinIcon } from '@/Components/Map/mapPin';

/**
 * Renders a Leaflet map of customer locations, geocoding any customer that
 * doesn't have lat/lon yet (via /geocode) and persisting the result back
 * (via PATCH /customers/{id}/coords) so it doesn't need to be geocoded again.
 *
 * `items` must be customer-shaped: { id, name, address, postal_code, city, lat, lon, ...}.
 * An item with `type: 'location'` is a location; its id may equal a customer's.
 * Each becomes a pin in `markerColor(item)`, with `markerNumber(item)` in its head
 * and a badge when `markerBadge(item)` says so. The map opens on the addresses
 * it already knows, not on the whole country.
 *
 * Zoomed out, pins that would overlap become one disc with their total number
 * and their colours in a ring, ordered like `colorOrder` (the legend's colours,
 * most pressing first). From street level on every pin stands on its own.
 */
export const markerKey = (item) => `${item.type ?? 'customer'}:${item.id}`;

const CLUSTER_RADIUS_PX = 40;
const STREET_LEVEL_ZOOM = 13;

export function useCustomerMapMarkers({
    items,
    markerColor,
    markerNumber = () => null,
    markerBadge = () => false,
    colorOrder = [],
    popupComponent,
    onMarkerClick,
    mapElementId = 'map',
    center = [52.1, 5.29],
    zoom = 7,
    coordsUrl = (item) => `/customers/${item.id}/coords`,
}) {
    const map = shallowRef(null);
    const markers = new Map();
    const clusters = L.markerClusterGroup({
        maxClusterRadius: CLUSTER_RADIUS_PX,
        disableClusteringAtZoom: STREET_LEVEL_ZOOM,
        showCoverageOnHover: false,
        iconCreateFunction: (cluster) => {
            const clustered = cluster.getAllChildMarkers().map((marker) => marker.options.item);
            return clusterIcon({
                colors: clustered.map(markerColor),
                order: colorOrder,
                number: clustered.reduce((total, item) => total + (markerNumber(item) ?? 1), 0),
                badge: clustered.some(markerBadge),
            });
        },
    });
    const queue = ref([]);
    const failedGeocodes = ref([]);
    const totalToGeocode = ref(0);
    const geocodedCount = ref(0);
    const geocodingStatus = ref('idle'); // idle, processing, success, failed

    const progress = computed(() => {
        if (totalToGeocode.value === 0) return 0;
        return (geocodedCount.value / totalToGeocode.value) * 100;
    });

    const addMarker = (item) => {
        if (item.lat == null || item.lon == null) {
            return;
        }
        if (markers.has(markerKey(item))) {
            return;
        }

        const div = document.createElement('div');
        render(h(popupComponent, { customer: item }), div);

        const icon = pinIcon({ color: markerColor(item), number: markerNumber(item), badge: markerBadge(item) });
        const marker = L.marker([item.lat, item.lon], { icon, item }).bindPopup(div);
        markers.set(markerKey(item), marker);
        clusters.addLayer(marker);

        if (onMarkerClick) {
            marker.on('click', () => onMarkerClick(item));
        }
    };

    /**
     * Takes the addresses with these keys out of the clusters and puts every
     * other one back, so a route's stops stay on their own spot at any zoom. A
     * pin keeps its open popup across the move when it is still in sight.
     */
    const keepApart = (keys) => {
        const apart = new Set(keys);
        markers.forEach((marker, key) => {
            const isWhereItBelongs = apart.has(key) !== clusters.hasLayer(marker);
            if (isWhereItBelongs) {
                return;
            }
            const wasOpen = marker.isPopupOpen();
            if (apart.has(key)) {
                clusters.removeLayer(marker);
                marker.addTo(map.value);
            } else {
                marker.remove();
                clusters.addLayer(marker);
            }
            if (wasOpen && map.value.hasLayer(marker)) {
                marker.openPopup();
            }
        });
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
        clusters.addTo(map.value);

        items.forEach((item) => {
            if (item.lat != null && item.lon != null) {
                addMarker(item);
            } else if (item.address || item.postal_code || item.city) {
                queue.value.push(item);
            }
        });

        const placed = items.filter((item) => item.lat != null && item.lon != null);
        if (placed.length) {
            map.value.fitBounds(L.latLngBounds(placed.map((item) => [item.lat, item.lon])), { padding: [40, 40], maxZoom: STREET_LEVEL_ZOOM });
        }

        totalToGeocode.value = queue.value.length;
        geocodedCount.value = 0;

        if (queue.value.length) {
            geocodeOne();
        }
    });

    return {
        map,
        keepApart,
        geocodingStatus,
        geocodedCount,
        totalToGeocode,
        failedGeocodes,
        progress,
    };
}
