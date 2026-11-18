<template>
    <TourLayer />
</template>

<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import TourLayer from '@/Components/Tour/TourLayer.vue';
import { useTour, announceTourPage, leaveTourPage, visibleRect } from '@/Composables/useTour';
import { addressesClose } from '@/Tours/mapExamples';

/**
 * The map rondleiding in a map window, which has no main layout and so no
 * layer of its own, and no button for it either: it goes on from the list that
 * opened the map. It points at a pin, a group of pins and the legend, opens a
 * pin's balloon, and picks a few addresses close together for the route
 * planner, as a person would by clicking them. Nothing leaves the window.
 */
const props = defineProps({
    map: { type: Object, default: null },
    items: { type: Array, required: true },
    /** The route planner panel, when this company has one. */
    planner: { type: Object, default: null },
    /** From useCustomerMapMarkers: a pin in sight that is not folded into a group. */
    pinInView: { type: Function, required: true },
});

const STOPS_SHOWN = 4;

/** A group of pins that is not panned out of sight. */
const clusterInSight = () => [...document.querySelectorAll('.leaflet-marker-pane .map-cluster')]
    .find((element) => {
        const { width, height } = visibleRect(element);

        return width > 0 && height > 0;
    }) ?? null;

onMounted(() => announceTourPage('kaart'));
onBeforeUnmount(() => leaveTourPage('kaart'));

useTour('kaart', {
    openPinPopup: () => {
        const marker = props.pinInView();
        if (!marker) {
            return null;
        }
        marker.openPopup();

        return () => marker.closePopup();
    },
    chooseStops: () => {
        const chosen = props.planner && props.map
            ? addressesClose(props.items, props.map, STOPS_SHOWN).filter((item) => !props.planner.isChosen(item))
            : [];
        chosen.forEach((item) => props.planner.toggleStop(item));

        return chosen.length ? () => chosen.forEach((item) => props.planner.toggleStop(item)) : null;
    },
}, {
    spots: {
        'map.pin': () => props.pinInView()?.getElement() ?? null,
        'map.cluster': clusterInSight,
        'map.popup': () => document.querySelector('.leaflet-popup'),
        'map.chosen': () => [...document.querySelectorAll('.leaflet-marker-pane .map-pin-chosen')],
    },
});
</script>
