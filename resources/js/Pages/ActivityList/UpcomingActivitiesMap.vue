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

const { items, routePlanner, dueColors } = defineProps({
    items: { type: Array, required: true },
    routePlanner: { type: Object, default: null },
    dueColors: { type: Object, required: true },
});

const planner = ref(null);

const EXPIRED = { label: 'Verlopen', color: dueColors.expired };
const BANDS = dueColors.bands.map(({ days, color }) => ({
    days,
    label: `Binnen ${days} ${days === 1 ? 'dag' : 'dagen'}`,
    color,
}));
const LATER = { label: 'Later', color: dueColors.later };
const LEGEND = [EXPIRED, ...BANDS, LATER];

const dueOf = (item) => {
    if (item.has_expired_assets) {
        return EXPIRED;
    }
    return BANDS.find(({ days }) => item.next_service_in_days <= days) ?? LATER;
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
