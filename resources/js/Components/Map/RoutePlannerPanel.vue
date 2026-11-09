<template>
    <div ref="panel" class="fixed top-3 left-14 z-[1000] w-96 max-w-[calc(100vw-4.5rem)]">
        <button type="button" :aria-pressed="active"
            class="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium shadow-md ring-1 ring-black/10"
            :class="active ? 'bg-blue-700 text-white hover:bg-blue-800' : 'bg-white text-gray-800 hover:bg-gray-50'"
            @click="toggleActive">
            <MapIcon class="h-5 w-5" />
            Route plannen
        </button>

        <div v-if="active"
            class="mt-2 max-h-[calc(100vh-6rem)] overflow-y-auto rounded-lg bg-white p-3 text-sm shadow-lg ring-1 ring-black/10">
            <h3 class="text-xs font-medium uppercase tracking-wide text-gray-500">Werkdag</h3>
            <div class="mt-1 flex items-center gap-2 text-gray-700">
                <input v-model="workdayStart" type="time" aria-label="Begin van de werkdag"
                    class="rounded-md border border-gray-300 px-2 py-1" @input="invalidate" />
                tot
                <input v-model="workdayEnd" type="time" aria-label="Einde van de werkdag"
                    class="rounded-md border border-gray-300 px-2 py-1" @input="invalidate" />
            </div>
            <label for="route-start" class="mt-3 block text-xs font-medium uppercase tracking-wide text-gray-500">
                Begin en einde
            </label>
            <select id="route-start" v-model="startChoice"
                class="mt-1 w-full rounded-md border border-gray-300 px-2 py-1 text-gray-800" @change="chooseStart">
                <option value="company" :disabled="!config.start">
                    {{ config.start ? 'Bedrijf' : 'Bedrijf (geen adres ingevuld)' }}
                </option>
                <option v-for="home in config.homes" :key="home.id" :value="home.id" :disabled="!home.has_address">
                    Thuis: {{ home.name }}{{ home.has_address ? '' : ' (geen thuisadres)' }}
                </option>
                <option value="elsewhere">Ander adres</option>
            </select>
            <AddressSearch v-if="startChoice === 'elsewhere'" class="mt-2" @pick="pickStart" />
            <p class="mt-2 text-gray-600">{{ startText }}</p>

            <p v-if="warning" role="alert" class="mt-3 rounded bg-red-50 p-2 font-medium text-red-700">{{ warning }}</p>

            <template v-for="section in sections" :key="section.id">
                <h3 class="mt-4 text-xs font-medium uppercase tracking-wide text-gray-500">
                    {{ section.title }} ({{ section.stops.length }})
                </h3>
                <ol class="divide-y divide-gray-100">
                    <RouteStopRow v-for="stop in section.stops" :key="stop.key" :stop="stop"
                        @remove="toggleStop(stop.item)" @minutes="(minutes) => changeStop(stop.key, { minutes })"
                        @urgent="changeStop(stop.key, { urgent: !stop.urgent })"
                        @before="(before) => changeStop(stop.key, { before })" />
                </ol>
            </template>
            <p v-if="!stops.length" class="mt-1 text-gray-600">
                Klik op de punten op de kaart die je wilt bezoeken.
            </p>

            <p v-if="error" class="mt-2 rounded bg-red-50 p-2 text-red-700">{{ error }}</p>

            <button v-if="!result" type="button" :disabled="!canCalculate"
                class="mt-3 w-full rounded-md bg-blue-700 px-3 py-2 font-medium text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50"
                @click="calculate">
                {{ busy ? 'Route berekenen…' : 'Route berekenen' }}
            </button>

            <div v-else-if="result.order.length" class="mt-3 border-t border-gray-200 pt-3">
                <p class="font-medium text-gray-900">Terug om {{ clock(result.finish) }}</p>
                <p class="text-xs text-gray-500">
                    {{ formatMeters(result.distance) }} · {{ formatSeconds(result.duration) }} rijden ·
                    {{ formatSeconds(timeOnSite) }} op locatie, zonder files.
                </p>
                <a v-for="(link, index) in googleLinks" :key="link" :href="link" target="_blank" rel="noopener"
                    class="mt-2 flex items-center justify-center gap-2 rounded-md bg-white px-3 py-2 font-medium text-gray-800 ring-1 ring-gray-300 hover:bg-gray-50">
                    <ArrowTopRightOnSquareIcon class="h-4 w-4" />
                    {{ googleLinks.length > 1 ? `Deel ${index + 1} openen in Google Maps` : 'Openen in Google Maps' }}
                </a>
                <p v-if="googleLinks.length > 1" class="mt-1 text-xs text-gray-500">
                    Google Maps neemt hoogstens tien adressen per keer, dus de route staat in delen klaar.
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import L from 'leaflet';
import axios from 'axios';
import { ArrowTopRightOnSquareIcon, MapIcon } from '@heroicons/vue/24/outline';
import RouteStopRow from '@/Components/Map/RouteStopRow.vue';
import AddressSearch from '@/Components/UI/AddressSearch.vue';
import { pinIcon } from '@/Components/Map/mapPin';
import { stopColor } from '@/Components/Map/routeStopColor';
import { markerKey } from '@/Composables/useCustomerMapMarkers';
import { formatAddress } from '@/Utilities/Utilities';
import { formatDurationLabel, formatTimeLabel, minutesFromTimeString } from '@/Utilities/plannerOverlaps';
import { plannerWorkday } from '@/Utilities/plannerDayView';

/**
 * Picks addresses on a map and asks the server for a working day along them:
 * urgent ones first, then as many as fit, in the fastest order, the work at
 * each begun by its own "vóór" time when it has one. The page passes marker
 * clicks in through `toggleStop`, and the `chosen` event tells it which
 * addresses are in, to keep them out of its clusters. This panel draws
 * everything of the route itself, on a layer of its own.
 *
 * `config` is what RoutePlanner::forMap() hands the page: the company start,
 * the plannable people whose home a route can start at (names only), the
 * company's working day and the planner's standard duration per visit. The
 * day this browser set up in the planner goes before the company's. A route
 * can also start at any address: searched, or clicked on the map. Chosen pins
 * let clicks through to the address underneath, which keeps its popup and
 * deselects it; `markerBadge` is the page's own badge rule, so they keep it.
 */
const props = defineProps({
    map: { type: Object, default: null },
    config: { type: Object, required: true },
    markerBadge: { type: Function, default: () => false },
});

const emit = defineEmits(['chosen']);

const GOOGLE_MAPS_POINTS_PER_LINK = 11;

const active = ref(false);
const stops = ref([]);
const workdayStart = ref('');
const workdayEnd = ref('');
const defaultStart = () => (props.config.start ? 'company' : 'elsewhere');
const startChoice = ref(defaultStart());
const elsewhere = ref(null);
const companyPoint = ref(props.config.start?.lat != null
    ? { lat: props.config.start.lat, lon: props.config.start.lon }
    : null);
const result = ref(null);
const error = ref(null);
const busy = ref(false);

const panel = ref(null);
const overlay = L.layerGroup();
let revision = 0;

const clock = (seconds) => formatTimeLabel(minutesFromTimeString(workdayStart.value) + Math.round(seconds / 60));

const routeStops = computed(() => {
    if (!result.value) {
        return stops.value.map((stop) => ({ ...stop, number: null, arrival: null, fits: true }));
    }
    const { order, arrivals } = result.value;
    return order.map((index, position) => ({
        ...stops.value[index],
        number: position + 1,
        arrival: clock(arrivals[position]),
        fits: true,
    }));
});

const leftOver = computed(() => (result.value
    ? result.value.unassigned.map(({ stop, reason }) => ({ ...stops.value[stop], number: null, arrival: null, fits: false, reason }))
    : []));

const warning = computed(() => {
    if (!result.value) {
        return null;
    }
    if (!result.value.order.length) {
        return 'Geen van de adressen past in deze werkdag. Bij elk adres staat waarom.';
    }
    const urgent = leftOver.value.filter((stop) => stop.urgent).length;
    if (urgent === 0) {
        return null;
    }
    return urgent === 1
        ? 'Een spoedadres past niet in deze werkdag. Onderaan staat waarom.'
        : `${urgent} spoedadressen passen niet in deze werkdag. Onderaan staat waarom.`;
});

const sections = computed(() => [
    { id: 'route', title: result.value ? 'Route' : 'Adressen', stops: routeStops.value },
    { id: 'left-over', title: 'Past niet in de werkdag', stops: leftOver.value },
].filter((section) => section.stops.length));

const timeOnSite = computed(() => routeStops.value.reduce((total, stop) => total + stop.minutes * 60, 0));

const home = computed(() => props.config.homes.find(({ id }) => id === startChoice.value) ?? null);

/** A home's address stays on the server; its house shows once a route came back. */
const startPoint = computed(() => {
    if (startChoice.value === 'elsewhere') {
        return elsewhere.value;
    }
    return home.value ? result.value?.start ?? null : companyPoint.value;
});

const startText = computed(() => {
    if (home.value) {
        return `Vanaf en terug naar het huis van ${home.value.name}.`;
    }
    if (startChoice.value === 'company') {
        return `Vanaf en terug naar ${props.config.start.address}.`;
    }
    if (!elsewhere.value) {
        return 'Zoek het adres, of klik op de kaart waar de route begint.';
    }
    return elsewhere.value.label
        ? `Vanaf en terug naar ${elsewhere.value.label}. Sleep het huisje om het te verplaatsen.`
        : 'Vanaf het huisje op de kaart. Sleep het om het te verplaatsen.';
});

const canCalculate = computed(() => !busy.value && stops.value.length > 0
    && (startChoice.value !== 'elsewhere' || elsewhere.value !== null));

const googleMapsLink = (points) => {
    const params = new URLSearchParams({
        api: '1',
        origin: points[0],
        destination: points[points.length - 1],
        travelmode: 'driving',
    });
    if (points.length > 2) {
        params.set('waypoints', points.slice(1, -1).join('|'));
    }
    return `https://www.google.com/maps/dir/?${params}`;
};

const googleLinks = computed(() => {
    if (!result.value) {
        return [];
    }
    const origin = startChoice.value === 'company'
        ? props.config.start.address
        : elsewhere.value?.label ?? `${result.value.start.lat},${result.value.start.lon}`;
    const points = [
        origin,
        ...routeStops.value.map(({ item }) => formatAddress(item) || `${item.lat},${item.lon}`),
        origin,
    ];

    const links = [];
    for (let first = 0; first < points.length - 1; first += GOOGLE_MAPS_POINTS_PER_LINK - 1) {
        links.push(googleMapsLink(points.slice(first, first + GOOGLE_MAPS_POINTS_PER_LINK)));
    }
    return links;
});

const formatSeconds = (seconds) => formatDurationLabel(Math.round(seconds / 60));

const formatMeters = (meters) => {
    const kilometers = meters / 1000;
    return kilometers.toLocaleString('nl-NL', { maximumFractionDigits: kilometers < 10 ? 1 : 0 }) + ' km';
};

const startIcon = L.divIcon({
    className: 'custom-marker',
    html: '<span class="flex h-7 w-7 items-center justify-center rounded-md border-2 border-white bg-gray-900 shadow"><svg viewBox="0 0 24 24" class="h-4 w-4 fill-white"><path d="M12 3 2 12h3v8h5v-5h4v5h5v-8h3z"/></svg></span>',
    iconSize: [28, 28],
    iconAnchor: [14, 14],
});

const draw = () => {
    overlay.clearLayers();
    if (!props.map || !active.value) {
        overlay.remove();
        return;
    }
    overlay.addTo(props.map);

    if (result.value) {
        L.polyline(result.value.path, { className: 'route-line', weight: 5, opacity: 0.75 }).addTo(overlay);
    }
    [...routeStops.value, ...leftOver.value].forEach((stop) => {
        const icon = pinIcon({ color: stopColor(stop), number: stop.number, badge: props.markerBadge(stop.item), chosen: true });
        L.marker([stop.item.lat, stop.item.lon], { icon, interactive: false, zIndexOffset: 1000 }).addTo(overlay);
    });
    if (startPoint.value) {
        L.marker([startPoint.value.lat, startPoint.value.lon], { icon: startIcon, draggable: startChoice.value === 'elsewhere', zIndexOffset: 2000 })
            .on('dragend', (event) => moveOwnStart(event.target.getLatLng()))
            .addTo(overlay);
    }
};

const showRoute = (path) => {
    const besidePanel = Math.min(panel.value.getBoundingClientRect().right + 24, props.map.getSize().x / 2);
    props.map.fitBounds(L.latLngBounds(path), { paddingTopLeft: [besidePanel, 40], paddingBottomRight: [40, 40] });
};

const invalidate = () => {
    revision++;
    result.value = null;
    error.value = null;
};

const choose = (chosen) => {
    stops.value = chosen;
    emit('chosen', chosen.map(({ key }) => key));
    invalidate();
};

const toggleStop = (item) => {
    if (!active.value) {
        return;
    }
    const key = markerKey(item);
    choose(stops.value.some((stop) => stop.key === key)
        ? stops.value.filter((stop) => stop.key !== key)
        : [...stops.value, { key, item, minutes: props.config.visit_minutes, urgent: false, before: null }]);
};

const changeStop = (key, changes) => {
    stops.value = stops.value.map((stop) => (stop.key === key ? { ...stop, ...changes } : stop));
    invalidate();
};

const chooseStart = () => {
    elsewhere.value = null;
    invalidate();
};

const pickStart = ({ lat, lon, label }) => {
    elsewhere.value = { lat, lon, label };
    props.map?.panTo([lat, lon]);
    invalidate();
};

const moveOwnStart = (latlng) => {
    elsewhere.value = { lat: latlng.lat, lon: latlng.lng, label: null };
    invalidate();
};

const placeOwnStart = (event) => {
    if (active.value && startChoice.value === 'elsewhere' && !elsewhere.value) {
        moveOwnStart(event.latlng);
    }
};

const toggleActive = () => {
    active.value = !active.value;
    const workday = plannerWorkday(props.config.workday);
    workdayStart.value = workday.start;
    workdayEnd.value = workday.end;
    startChoice.value = defaultStart();
    elsewhere.value = null;
    choose([]);
};

const messageFrom = (exception) => {
    const response = exception.response;
    if (response?.status === 422) {
        return Object.values(response.data.errors ?? {})[0]?.[0] ?? response.data.message;
    }
    if (response?.status === 429) {
        return 'Je hebt net veel routes achter elkaar berekend. Probeer het over een minuut opnieuw.';
    }
    return 'De route kon niet worden berekend. Probeer het later opnieuw.';
};

const calculate = async () => {
    const asked = revision;
    const question = {
        stops: stops.value.map(({ item, minutes, urgent, before }) => ({
            lat: item.lat,
            lon: item.lon,
            name: item.name,
            minutes,
            urgent,
            before,
        })),
        start: startChoice.value === 'elsewhere' ? { lat: elsewhere.value.lat, lon: elsewhere.value.lon } : null,
        start_user_id: home.value?.id ?? null,
        workday: { start: workdayStart.value, end: workdayEnd.value },
    };
    busy.value = true;
    error.value = null;
    try {
        await axios.get('/sanctum/csrf-cookie');
        if (asked !== revision) {
            return;
        }
        const { data } = await axios.post('/routeplanner', question);
        if (asked !== revision) {
            return;
        }
        result.value = data;
        if (startChoice.value === 'company') {
            companyPoint.value = data.start;
        }
        if (data.path.length) {
            showRoute(data.path);
        }
    } catch (exception) {
        if (asked === revision) {
            error.value = messageFrom(exception);
        }
    } finally {
        busy.value = false;
    }
};

watch([() => props.map, active, routeStops, leftOver, startPoint], draw);

watch(() => props.map, (map, previous) => {
    previous?.off('click', placeOwnStart);
    map?.on('click', placeOwnStart);
}, { immediate: true });

onBeforeUnmount(() => {
    props.map?.off('click', placeOwnStart);
    overlay.remove();
});

defineExpose({ toggleStop });
</script>
