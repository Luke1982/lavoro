<template>
    <div class="relative h-full">
        <div ref="container" class="w-full h-full z-0"></div>
        <div v-if="!points.length" class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center p-4">
            <p
                class="rounded-lg bg-white/95 px-3 py-2 text-center text-sm text-gray-600 shadow-lavoro-box dark:bg-slate-900/95 dark:text-slate-300 dark:shadow-none">
                Niets ingepland op een bekend adres in deze periode
            </p>
        </div>

        <!--
            Een kaart die minder spelden toont dan er afspraken zijn moet dat
            zelf zeggen, anders leest hij als "meer is er niet".
        -->
        <p v-if="unplaced > 0"
            class="pointer-events-none absolute inset-x-0 bottom-0 z-10 bg-amber-50/95 px-3 py-1.5 text-center text-xs text-amber-800 dark:bg-amber-950/90 dark:text-amber-200">
            {{ unplaced }} van {{ planned }} afspraken zonder bekende coördinaten
        </p>
    </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const props = defineProps({
    points: { type: Array, default: () => [] },
    /** Hoeveel afspraken er in de periode staan, en hoeveel er geen speld kregen. */
    planned: { type: Number, default: 0 },
    unplaced: { type: Number, default: 0 },
});

const container = ref(null);
const map = ref(null);
const markers = ref(null);

/**
 * Eén punt per adres, dus het cijfer op de speld vertelt hoeveel werkbonnen daar
 * liggen. Staat er maar één en is die af, dan hoeft er geen cijfer bij en is het
 * vinkje genoeg.
 */
const CHECK = '<svg viewBox="0 0 20 20" width="12" height="12" fill="white">'
    + '<path d="M8 13.5 4.5 10l1.4-1.4L8 10.7l6.1-6.1L15.5 6 8 13.5Z"/></svg>';

const PIN_STYLE = 'display:flex;align-items:center;justify-content:center;width:26px;height:26px;'
    + 'border-radius:9999px;color:#fff;font-size:12px;font-weight:700;border:2px solid #fff;'
    + 'box-shadow:0 1px 4px rgba(15,23,42,.35)';

const isDone = (point) => point.done >= point.appointments;

const pinFor = (point) => {
    const done = isDone(point);
    const color = done ? 'var(--dash-series-2)' : 'var(--dash-series-1)';
    const label = point.total > 1 ? String(point.total) : (done ? CHECK : '');

    return L.divIcon({
        className: 'dashboard-pin',
        html: `<span style="${PIN_STYLE};background:${color}">${label}</span>`,
        iconSize: [26, 26],
        iconAnchor: [13, 13],
    });
};

const popupFor = (point) => {
    const place = [point.name, point.city].filter(Boolean).join(' — ');
    const orders = point.total === 1 ? '1 werkbon' : `${point.total} werkbonnen`;
    const appointments = point.appointments === 1 ? '1 afspraak' : `${point.appointments} afspraken`;

    return `<strong>${place}</strong><br>${orders} · ${appointments}, waarvan ${point.done} afgerond`;
};

const draw = () => {
    if (!map.value) return;

    markers.value.clearLayers();

    props.points.forEach((point) => {
        L.marker([point.lat, point.lon], { icon: pinFor(point) })
            .bindPopup(popupFor(point))
            .addTo(markers.value);
    });

    fit();
};

const fit = () => {
    if (!map.value || !props.points.length) return;

    map.value.fitBounds(markers.value.getBounds().pad(0.25), { animate: false });
};

onMounted(() => {
    map.value = L.map(container.value, { zoomControl: false, attributionControl: false })
        .setView([52.1, 5.29], 7);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>',
    }).addTo(map.value);

    L.control.attribution({ position: 'bottomleft', prefix: false }).addTo(map.value);
    L.control.zoom({ position: 'bottomright' }).addTo(map.value);

    const recenter = L.control({ position: 'bottomright' });
    recenter.onAdd = () => {
        const bar = L.DomUtil.create('div', 'leaflet-bar');
        const link = L.DomUtil.create('a', '', bar);
        link.href = '#';
        link.title = 'Alle werkbonnen in beeld';
        link.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"'
            + ' stroke-width="2" style="margin:5px auto;display:block"><circle cx="12" cy="12" r="7"/>'
            + '<path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>';
        L.DomEvent.on(link, 'click', (event) => {
            L.DomEvent.stop(event);
            fit();
        });
        return bar;
    };
    recenter.addTo(map.value);

    markers.value = L.featureGroup().addTo(map.value);

    draw();
});

onBeforeUnmount(() => {
    map.value?.remove();
    map.value = null;
});

watch(() => props.points, draw, { deep: true });
</script>
