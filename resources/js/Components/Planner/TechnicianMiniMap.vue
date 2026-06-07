<template>
    <div ref="map_el" class="w-full h-full rounded overflow-hidden" />
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

const props = defineProps({
    lat: { type: Number, required: true },
    lng: { type: Number, required: true },
})

const map_el = ref(null)
let map = null

onMounted(() => {
    // Delay init past the row-expand CSS transition (200ms)
    setTimeout(() => {
        if (!map_el.value) return
        map = L.map(map_el.value, {
            zoomControl: false,
            attributionControl: false,
            dragging: false,
            touchZoom: false,
            doubleClickZoom: false,
            scrollWheelZoom: false,
            boxZoom: false,
            keyboard: false,
            tap: false,
        }).setView([props.lat, props.lng], 14)

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
        }).addTo(map)

        L.circleMarker([props.lat, props.lng], {
            radius: 6,
            fillColor: '#3b82f6',
            color: '#fff',
            weight: 2,
            opacity: 1,
            fillOpacity: 1,
        }).addTo(map)
    }, 250)
})

onUnmounted(() => {
    map?.remove()
    map = null
})
</script>
