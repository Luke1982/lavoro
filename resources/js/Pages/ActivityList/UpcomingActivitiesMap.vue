<template>
    <div class="w-screen h-screen">
        <div id="map" class="w-full h-full"></div>
        <GeocodingStatusOverlay :status="geocodingStatus" :geocoded-count="geocodedCount"
            :total-to-geocode="totalToGeocode" :progress="progress" :failed-geocodes="failedGeocodes"
            :total="items.length" />
        <MapLegend title="Eerstvolgende keuring" :entries="LEGEND" counted="machines" />
        <RoutePlannerPanel v-if="routePlanner" ref="planner" :map="map" :config="routePlanner"
            @chosen="keepApart" />
        <CloseMapWindow />
    </div>
</template>

<script setup>
import { ref } from 'vue';
import EmptyLayout from '@/Layouts/EmptyLayout.vue';
import CloseMapWindow from '@/Components/Map/CloseMapWindow.vue';
import MapLegend from '@/Components/Map/MapLegend.vue';
import RoutePlannerPanel from '@/Components/Map/RoutePlannerPanel.vue';
import MapPopup from '@/Components/MapPopup.vue';
import GeocodingStatusOverlay from '@/Components/GeocodingStatusOverlay.vue';
import { useCustomerMapMarkers } from '@/Composables/useCustomerMapMarkers';

defineOptions({ layout: EmptyLayout });

const { items, routePlanner } = defineProps({
    items: { type: Array, required: true },
    routePlanner: { type: Object, default: null },
});

const planner = ref(null);

const DUE = {
    expired: { label: 'Verlopen', color: 'var(--map-due-expired)' },
    month: { label: 'Binnen 30 dagen', color: 'var(--map-due-month)' },
    twoMonths: { label: 'Binnen 60 dagen', color: 'var(--map-due-two-months)' },
    later: { label: 'Later', color: 'var(--map-due-later)' },
};
const LEGEND = Object.values(DUE);

const dueOf = (item) => {
    const days = item.next_service_in_days;
    if (item.has_expired_assets) {
        return DUE.expired;
    }
    if (days <= 30) {
        return DUE.month;
    }
    return days <= 60 ? DUE.twoMonths : DUE.later;
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

const { map, keepApart, geocodingStatus, geocodedCount, totalToGeocode, failedGeocodes, progress } = useCustomerMapMarkers({
    items,
    markerColor: (item) => dueOf(item).color,
    markerNumber: (item) => item.assets.length,
    colorOrder: LEGEND.map(({ color }) => color),
    popupComponent: MapPopup,
    onMarkerClick: (item) => {
        planner.value?.toggleStop(item);
        scrollToCustomer(item);
    },
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
