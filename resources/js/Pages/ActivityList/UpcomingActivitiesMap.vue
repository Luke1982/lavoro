<template>
    <div class="w-screen h-screen">
        <div id="map" class="w-full h-full"></div>
        <GeocodingStatusOverlay :status="geocodingStatus" :geocoded-count="geocodedCount"
            :total-to-geocode="totalToGeocode" :progress="progress" :failed-geocodes="failedGeocodes"
            :total="items.length" />
    </div>
</template>

<script setup>
import EmptyLayout from '@/Layouts/EmptyLayout.vue';
import MapPopup from '@/Components/MapPopup.vue';
import GeocodingStatusOverlay from '@/Components/GeocodingStatusOverlay.vue';
import { useCustomerMapMarkers } from '@/Composables/useCustomerMapMarkers';

defineOptions({ layout: EmptyLayout });

const { items } = defineProps({
    items: { type: Array, required: true }
});

const classifyColor = (days) => {
    if (days === null || days === undefined) {
        return 'gray';
    }
    if (days <= 30) {
        return 'orange';
    }
    if (days <= 60) {
        return 'yellow';
    }
    return 'green';
};

const scrollToCustomer = (item) => {
    const customerId = item.customer_id ?? item.id;
    setTimeout(() => {
        if (window.opener && window.opener.scrollToCustomer) {
            try {
                window.opener.scrollToCustomer(customerId);
            } catch (e) {
                console.debug('scrollToCustomer failed', e);
            }
        } else if (window.opener) {
            try {
                window.opener.postMessage({ type: 'scrollToCustomer', id: customerId }, '*');
            } catch (e) {
                console.debug('postMessage scrollToCustomer failed', e);
            }
        }
    }, 50);
};

const { geocodingStatus, geocodedCount, totalToGeocode, failedGeocodes, progress } = useCustomerMapMarkers({
    items,
    markerColor: (c) => c.has_expired_assets ? 'red' : classifyColor(c.next_service_in_days),
    popupComponent: MapPopup,
    onMarkerClick: scrollToCustomer,
    coordsUrl: (item) => item.type === 'location'
        ? `/locations/${item.id}/coords`
        : `/customers/${item.id}/coords`,
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
