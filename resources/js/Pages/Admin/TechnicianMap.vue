<template>
    <div class="relative" style="height: calc(100vh - 4rem)" v-if="props.pings">
        <div ref="map_el" class="w-full h-full" />

        <div class="absolute top-4 left-4 z-[1000] bg-white dark:bg-slate-800 rounded-lg shadow-lg p-3 min-w-40">
            <p class="text-sm font-semibold text-gray-900 dark:text-slate-100">Technici (laatste 8u)</p>
            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{{ props.pings.length }} zichtbaar</p>
        </div>
    </div>
    <div v-else class="flex items-center justify-center h-full">
        <p class="text-slate-500 text-xs pb-5 pt-3 dark:text-slate-400 px-5 text-center">Monteurlocaties: Geen data
            beschikbaar</p>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const props = defineProps({
    pings: { type: Array, required: true },
});

const COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];

function user_color(id) {
    return COLORS[id % COLORS.length];
}

function initials(name) {
    if (!name) return '?';
    return name.trim().split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase();
}

function format_time(iso) {
    const d = new Date(iso);
    const now = new Date();
    const date_str = d.toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'short' });
    const time_str = d.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
    const is_today = d.toDateString() === now.toDateString();
    return is_today ? time_str : `${date_str} ${time_str}`;
}

function make_icon(ping) {
    const color = user_color(ping.user?.id ?? 0);
    const name = ping.user?.name ?? 'Onbekend';
    const avatar = ping.user?.avatar;
    const heading = ping.heading;
    const time = format_time(ping.recorded_at);

    const arrow_svg = heading != null
        ? `<div style="
                position:absolute;
                top:-18px;
                left:50%;
                transform:translateX(-50%) rotate(${heading}deg);
                transform-origin:center bottom;
                width:0;height:0;
                border-left:7px solid transparent;
                border-right:7px solid transparent;
                border-bottom:16px solid ${color};
                filter:drop-shadow(0 1px 2px rgba(0,0,0,.4));
            "></div>`
        : '';

    const avatar_inner = avatar
        ? `<img src="${avatar}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
           <span style="display:none;align-items:center;justify-content:center;width:100%;height:100%;font-weight:700;font-size:14px;color:#fff;">${initials(name)}</span>`
        : `<span style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;font-weight:700;font-size:14px;color:#fff;">${initials(name)}</span>`;

    const html = `
        <div style="position:relative;display:flex;flex-direction:column;align-items:center;gap:3px;">
            ${arrow_svg}
            <div style="
                width:40px;height:40px;border-radius:50%;
                background:${color};
                border:3px solid #fff;
                box-shadow:0 2px 8px rgba(0,0,0,.35);
                overflow:hidden;
                position:relative;
            ">${avatar_inner}</div>
            <div style="
                background:rgba(8,16,32,.85);
                color:#fff;
                font-size:10px;
                font-weight:600;
                padding:2px 6px;
                border-radius:10px;
                white-space:nowrap;
                box-shadow:0 1px 4px rgba(0,0,0,.3);
                font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
            ">${time}</div>
        </div>`;

    return L.divIcon({
        html,
        className: '',
        iconSize: [60, 70],
        iconAnchor: [30, 30],
        popupAnchor: [0, -35],
    });
}

const map_el = ref(null);
let map = null;

onMounted(() => {
    map = L.map(map_el.value).setView([52.3, 5.3], 8);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 18,
    }).addTo(map);

    props.pings.forEach((ping) => {
        L.marker([ping.lat, ping.lng], { icon: make_icon(ping) })
            .addTo(map)
            .bindPopup(`<strong>${ping.user?.name ?? 'Onbekend'}</strong><br>${format_time(ping.recorded_at)}`);
    });

    if (props.pings.length > 0) {
        map.fitBounds(
            L.latLngBounds(props.pings.map((p) => [p.lat, p.lng])),
            { padding: [80, 80] }
        );
    }
});

onUnmounted(() => {
    map?.remove();
    map = null;
});
</script>
