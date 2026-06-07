<template>
    <div class="relative w-full h-full rounded overflow-hidden">
        <div ref="map_el" class="w-full h-full" />
        <div v-if="formattedTime"
            class="absolute bottom-1 left-1 z-[1000] bg-black/60 text-white text-[10px] font-medium px-1.5 py-0.5 rounded pointer-events-none">
            {{ formattedTime }}
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import dayjs from '@/Utilities/dayjs'
import { initials, nlTime } from '@/Utilities/Utilities'

const props = defineProps({
    ping: { type: Object, required: true },
})

const COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16']

function user_color(id) {
    return COLORS[(id ?? 0) % COLORS.length]
}

function format_time(iso) {
    const d = dayjs(iso)
    return d.isSame(dayjs(), 'day')
        ? nlTime(d.toDate())
        : d.format('ddd D MMM') + ' ' + nlTime(d.toDate())
}

function make_icon(ping) {
    const color = user_color(ping.user?.id)
    const name = ping.user?.name ?? 'Onbekend'
    const avatar = ping.user?.avatar
    const heading = ping.heading

    const arrow_svg = heading != null
        ? `<div style="
                position:absolute;top:-18px;left:50%;
                transform:translateX(-50%) rotate(${heading}deg);
                transform-origin:center bottom;
                width:0;height:0;
                border-left:7px solid transparent;
                border-right:7px solid transparent;
                border-bottom:16px solid ${color};
                filter:drop-shadow(0 1px 2px rgba(0,0,0,.4));
            "></div>`
        : ''

    const avatar_inner = avatar
        ? `<img src="${avatar}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
           <span style="display:none;align-items:center;justify-content:center;width:100%;height:100%;font-weight:700;font-size:14px;color:#fff;">${initials(name)}</span>`
        : `<span style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;font-weight:700;font-size:14px;color:#fff;">${initials(name)}</span>`

    const html = `
        <div style="position:relative;display:flex;flex-direction:column;align-items:center;gap:3px;">
            ${arrow_svg}
            <div style="
                width:36px;height:36px;border-radius:50%;
                background:${color};border:3px solid #fff;
                box-shadow:0 2px 8px rgba(0,0,0,.35);
                overflow:hidden;position:relative;
            ">${avatar_inner}</div>
        </div>`

    return L.divIcon({ html, className: '', iconSize: [50, 54], iconAnchor: [25, 25], popupAnchor: [0, -30] })
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

        L.marker([props.ping.lat, props.ping.lng], { icon: make_icon(props.ping) })
            .addTo(map)
    }, 250)
})

onUnmounted(() => {
    map?.remove()
    map = null
})
</script>
