<template>
    <div class="w-screen h-screen">
        <div id="map" class="w-full h-full"></div>
        <GeocodingStatusOverlay :status="geocodingStatus" :geocoded-count="geocodedCount"
            :total-to-geocode="totalToGeocode" :progress="progress" :failed-geocodes="failedGeocodes"
            :total="items.length" />
        <MapLegend title="Hoogste prioriteit op het adres" :entries="LEGEND" counted="storingen"
            :badge="seesContracts ? 'Machine onder onderhoudscontract' : null" />
        <RoutePlannerPanel v-if="routePlanner" ref="planner" :map="map" :config="routePlanner"
            :marker-badge="onContract" @chosen="keepApart" />
        <CloseMapWindow />
    </div>
</template>

<script setup>
import { ref } from 'vue';
import EmptyLayout from '@/Layouts/EmptyLayout.vue';
import CloseMapWindow from '@/Components/Map/CloseMapWindow.vue';
import MapLegend from '@/Components/Map/MapLegend.vue';
import RoutePlannerPanel from '@/Components/Map/RoutePlannerPanel.vue';
import TicketMapPopup from '@/Components/TicketMapPopup.vue';
import GeocodingStatusOverlay from '@/Components/GeocodingStatusOverlay.vue';
import { useCustomerMapMarkers } from '@/Composables/useCustomerMapMarkers';

defineOptions({ layout: EmptyLayout });

const { items, seesContracts, routePlanner } = defineProps({
    items: { type: Array, required: true },
    seesContracts: { type: Boolean, default: false },
    routePlanner: { type: Object, default: null },
});

const PRIORITIES = [
    { key: 'hoog', label: 'Hoog', color: 'var(--map-priority-high)' },
    { key: 'normaal', label: 'Normaal', color: 'var(--map-priority-normal)' },
    { key: 'laag', label: 'Laag', color: 'var(--map-priority-low)' },
];
const NO_PRIORITY = { label: 'Geen prioriteit', color: 'var(--map-priority-none)' };
const LEGEND = [...PRIORITIES, NO_PRIORITY];

const planner = ref(null);

const markerColor = (item) => (PRIORITIES.find(({ key }) => item.tickets.some(
    (ticket) => ticket.priority?.toLowerCase() === key,
)) ?? NO_PRIORITY).color;

const onContract = (item) => item.on_contract;

const { map, keepApart, geocodingStatus, geocodedCount, totalToGeocode, failedGeocodes, progress } = useCustomerMapMarkers({
    items,
    markerColor,
    markerNumber: (item) => item.tickets.length,
    markerBadge: onContract,
    colorOrder: LEGEND.map(({ color }) => color),
    popupComponent: TicketMapPopup,
    onMarkerClick: (item) => planner.value?.toggleStop(item),
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
