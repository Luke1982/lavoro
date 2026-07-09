<template>
    <div class="w-screen h-screen">
        <div id="map" class="w-full h-full"></div>
        <GeocodingStatusOverlay :status="geocodingStatus" :geocoded-count="geocodedCount"
            :total-to-geocode="totalToGeocode" :progress="progress" :failed-geocodes="failedGeocodes"
            :total="customers.length" />
    </div>
</template>

<script setup>
import EmptyLayout from '@/Layouts/EmptyLayout.vue';
import TicketMapPopup from '@/Components/TicketMapPopup.vue';
import GeocodingStatusOverlay from '@/Components/GeocodingStatusOverlay.vue';
import { useCustomerMapMarkers } from '@/Composables/useCustomerMapMarkers';

defineOptions({ layout: EmptyLayout });

const { customers } = defineProps({
    customers: { type: Array, required: true }
});

const priorityRank = { hoog: 3, normaal: 2, laag: 1 };
const priorityColor = { hoog: '#dc2626', normaal: '#f59e0b', laag: '#16a34a' };

const markerColor = (customer) => {
    const highest = (customer.tickets || []).reduce((best, ticket) => {
        const key = (ticket.priority || '').toLowerCase();
        const rank = priorityRank[key] ?? 0;
        return rank > best.rank ? { rank, key } : best;
    }, { rank: 0, key: null });
    return priorityColor[highest.key] ?? '#9ca3af';
};

const { geocodingStatus, geocodedCount, totalToGeocode, failedGeocodes, progress } = useCustomerMapMarkers({
    items: customers,
    markerColor,
    popupComponent: TicketMapPopup,
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
