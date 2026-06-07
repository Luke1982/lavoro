<template>
    <div class="relative w-full h-full rounded overflow-hidden">
        <div ref="map_el" class="w-full h-full" />
        <div
            class="absolute bottom-1 left-1 z-[1000] bg-black/60 text-white text-[10px] font-medium px-1.5 py-0.5 rounded pointer-events-none">
            {{ formattedTime }}
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

const props = defineProps({
    ping: { type: Object, required: true },
})

function format_time(iso) {
    const d = new Date(iso)
    const now = new Date()
    const time_str = d.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' })
    if (d.toDateString() === now.toDateString()) return time_str
    return d.toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'short' }) + ' ' + time_str
}

const formattedTime = computed(() => props.ping.recorded_at ? format_time(props.ping.recorded_at) : '')

const map_el = ref(null)
let map = null

onMounted(() => {
    setTimeout(() => {
        if (!map_el.value) return
        map = L.map(map_el.value).setView([props.ping.lat, props.ping.lng], 14)

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 18,
        }).addTo(map)

        L.circleMarker([props.ping.lat, props.ping.lng], {
            radius: 7,
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
