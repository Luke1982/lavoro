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
                <option v-for="person in config.people" :key="person.id" :value="person.id" :disabled="!person.has_address">
                    Thuis: {{ person.name }}{{ person.has_address ? '' : ' (geen thuisadres)' }}
                </option>
                <option value="elsewhere">Ander adres</option>
            </select>
            <AddressSearch v-if="startChoice === 'elsewhere'" class="mt-2" @pick="pickStart" />
            <p class="mt-2 text-gray-600">{{ startText }}</p>

            <div v-if="warnings.length" role="alert" class="mt-3 space-y-1 rounded bg-red-50 p-2 font-medium text-red-700">
                <p v-for="text in warnings" :key="text">{{ text }}</p>
            </div>

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
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="font-medium text-gray-900">Terug om {{ clock(result.finish) }}</p>
                        <p class="text-xs text-gray-500">
                            {{ formatMeters(result.distance) }} · {{ formatSeconds(result.duration) }} rijden ·
                            {{ formatSeconds(timeOnSite) }} op locatie, zonder files.
                        </p>
                    </div>
                    <button v-if="result.order.length > 1" type="button" :aria-pressed="reversed"
                        :disabled="busy || assigning" title="Dezelfde adressen in omgekeerde volgorde, met hetzelfde begin en einde"
                        class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-medium ring-1 disabled:cursor-not-allowed disabled:opacity-50"
                        :class="reversed ? 'bg-blue-700 text-white ring-blue-700 hover:bg-blue-800' : 'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50'"
                        @click="reverse">
                        <ArrowsRightLeftIcon class="h-4 w-4" />
                        {{ busy ? 'Andersom…' : 'Andersom' }}
                    </button>
                </div>
                <a v-for="(link, index) in googleLinks" :key="link" :href="link" target="_blank" rel="noopener"
                    class="mt-2 flex items-center justify-center gap-2 rounded-md bg-white px-3 py-2 font-medium text-gray-800 ring-1 ring-gray-300 hover:bg-gray-50">
                    <ArrowTopRightOnSquareIcon class="h-4 w-4" />
                    {{ googleLinks.length > 1 ? `Deel ${index + 1} openen in Google Maps` : 'Openen in Google Maps' }}
                </a>
                <p v-if="googleLinks.length > 1" class="mt-1 text-xs text-gray-500">
                    Google Maps neemt hoogstens tien adressen per keer, dus de route staat in delen klaar.
                </p>

                <form v-if="assignable.length" class="mt-3 border-t border-gray-200 pt-3" @submit.prevent="assign">
                    <h3 class="text-xs font-medium uppercase tracking-wide text-gray-500">In planning zetten</h3>
                    <div class="mt-1 flex items-center gap-2">
                        <select v-model="assignee" aria-label="Monteur"
                            class="min-w-0 flex-1 rounded-md border border-gray-300 px-2 py-1 text-gray-800">
                            <option :value="null" disabled>Kies een monteur</option>
                            <option v-for="person in assignable" :key="person.id" :value="person.id">
                                {{ person.name }}
                            </option>
                        </select>
                        <input v-model="assignDate" type="date" aria-label="Dag"
                            class="rounded-md border border-gray-300 px-2 py-1 text-gray-800" />
                    </div>
                    <button type="submit" :disabled="assigning || busy"
                        class="mt-2 w-full rounded-md bg-white px-3 py-2 font-medium text-gray-800 ring-1 ring-gray-300 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ assigning ? 'In planning zetten…' : 'In planning zetten' }}
                    </button>
                    <p v-if="assigned" role="status" class="mt-2 text-green-700">{{ assigned }}</p>
                    <p v-if="assignError" class="mt-2 rounded bg-red-50 p-2 text-red-700">{{ assignError }}</p>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import L from 'leaflet';
import axios from 'axios';
import { ArrowsRightLeftIcon, ArrowTopRightOnSquareIcon, MapIcon } from '@heroicons/vue/24/outline';
import RouteStopRow from '@/Components/Map/RouteStopRow.vue';
import AddressSearch from '@/Components/UI/AddressSearch.vue';
import { pinIcon } from '@/Components/Map/mapPin';
import { stopColor } from '@/Components/Map/routeStopColor';
import { markerKey } from '@/Composables/useCustomerMapMarkers';
import { formatAddress, formatLocalDateAsISO } from '@/Utilities/Utilities';
import dayjs from '@/Utilities/dayjs';
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
 * the plannable people (names only) whose home a route can start at and in
 * whose day this person may put it, the company's working day and the
 * planner's standard duration per visit. The day this browser set up in the
 * planner goes before the company's. A route can also start at any address:
 * searched, or clicked on the map. Chosen pins let clicks through to the
 * address underneath, which keeps its popup and deselects it; `markerBadge` is
 * the page's own badge rule, so they keep it.
 *
 * A calculated route can be driven the other way round: the same stops, start
 * and end, with the times worked out again, and flagged where that misses a
 * vóór-time or the end of the day. It can also be put in someone's day: the
 * planner then shows its Google Maps links at the top of that day
 * (DayRouteController).
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
const calculated = ref(null);
const turned = ref(null);
const reversed = ref(false);
/** The route as shown: as calculated, or the same stops the other way round. */
const result = computed(() => (reversed.value ? turned.value : calculated.value));
const error = ref(null);
const busy = ref(false);
const assignee = ref(null);
const assignDate = ref(formatLocalDateAsISO(dayjs().add(1, 'day')));
const assigning = ref(false);
const assigned = ref(null);
const assignError = ref(null);

const panel = ref(null);
const overlay = L.layerGroup();
let revision = 0;

const clock = (seconds) => formatTimeLabel(minutesFromTimeString(workdayStart.value) + Math.round(seconds / 60));

/** By the minute, as `clock` shows it: a flag never contradicts the time beside it. */
const isAfter = (seconds, time) => Math.round(seconds / 60)
    > minutesFromTimeString(time) - minutesFromTimeString(workdayStart.value);

const routeStops = computed(() => {
    if (!result.value) {
        return stops.value.map((stop) => ({ ...stop, number: null, arrival: null, fits: true }));
    }
    const { order, arrivals } = result.value;
    return order.map((index, position) => {
        const stop = stops.value[index];
        const late = Boolean(stop.before) && isAfter(arrivals[position], stop.before);
        return {
            ...stop,
            number: position + 1,
            arrival: clock(arrivals[position]),
            fits: true,
            late,
            reason: late ? `Niet vóór ${stop.before} te halen.` : null,
        };
    });
});

/** What did not fit is the calculation's: the other way round drives only what did. */
const leftOver = computed(() => (calculated.value
    ? calculated.value.unassigned.map(({ stop, reason }) => ({ ...stops.value[stop], number: null, arrival: null, fits: false, reason }))
    : []));

/**
 * What did not fit, and where the route as shown misses a vóór-time or the end
 * of the day: a calculated route never does, the other way round may.
 */
const warnings = computed(() => {
    if (!result.value) {
        return [];
    }
    if (!result.value.order.length) {
        return ['Geen van de adressen past in deze werkdag. Bij elk adres staat waarom.'];
    }
    const urgent = leftOver.value.filter((stop) => stop.urgent).length;
    const late = routeStops.value.filter((stop) => stop.late).length;
    return [
        urgent === 1 && 'Een spoedadres past niet in deze werkdag. Onderaan staat waarom.',
        urgent > 1 && `${urgent} spoedadressen passen niet in deze werkdag. Onderaan staat waarom.`,
        late === 1 && 'In deze volgorde wordt een vóór-tijd niet gehaald. Bij het adres staat welke.',
        late > 1 && `In deze volgorde worden ${late} vóór-tijden niet gehaald. Bij de adressen staat welke.`,
        isAfter(result.value.finish, workdayEnd.value)
            && `In deze volgorde ben je pas om ${clock(result.value.finish)} terug, na het einde van de werkdag.`,
    ].filter(Boolean);
});

const sections = computed(() => [
    { id: 'route', title: result.value ? 'Route' : 'Adressen', stops: routeStops.value },
    { id: 'left-over', title: 'Past niet in de werkdag', stops: leftOver.value },
].filter((section) => section.stops.length));

const timeOnSite = computed(() => routeStops.value.reduce((total, stop) => total + stop.minutes * 60, 0));

const home = computed(() => props.config.people.find(({ id }) => id === startChoice.value) ?? null);

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

const assignable = computed(() => props.config.people.filter(({ may_assign }) => may_assign));

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
    calculated.value = null;
    turned.value = null;
    reversed.value = false;
    error.value = null;
    assigned.value = null;
    assignError.value = null;
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

/**
 * The route for what is in the panel now, or null when it failed or something
 * changed while it was on its way. With `order` the stops are driven in that
 * order instead of the fastest one.
 */
const ask = async (order = null) => {
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
        order,
    };
    busy.value = true;
    error.value = null;
    try {
        await axios.get('/sanctum/csrf-cookie');
        if (asked !== revision) {
            return null;
        }
        const { data } = await axios.post('/routeplanner', question);
        return asked === revision ? data : null;
    } catch (exception) {
        if (asked === revision) {
            error.value = messageFrom(exception);
        }
        return null;
    } finally {
        busy.value = false;
    }
};

const calculate = async () => {
    const data = await ask();
    if (!data) {
        return;
    }
    calculated.value = data;
    if (startChoice.value === 'company') {
        companyPoint.value = data.start;
    }
    if (data.path.length) {
        showRoute(data.path);
    }
};

/** The other way round is calculated once; after that the button only switches. */
const reverse = async () => {
    if (!turned.value) {
        turned.value = await ask([...calculated.value.order].reverse());
    }
    if (turned.value) {
        reversed.value = !reversed.value;
        assigned.value = null;
        assignError.value = null;
    }
};

const assign = async () => {
    const asked = revision;
    const route = {
        user_id: assignee.value,
        date: assignDate.value,
        stops: routeStops.value.map(({ item, arrival }) => ({ name: item.name, arrival })),
        finish: clock(result.value.finish),
        links: googleLinks.value,
    };
    assigning.value = true;
    assigned.value = null;
    assignError.value = null;
    try {
        await axios.get('/sanctum/csrf-cookie');
        const { data } = await axios.post('/api/dayroutes', route);
        if (asked === revision) {
            assigned.value = data.replaced
                ? `De route van ${data.user_name} voor ${data.day_label} is vervangen.`
                : `Staat in de planning van ${data.user_name} op ${data.day_label}.`;
        }
    } catch (exception) {
        if (asked === revision) {
            assignError.value = exception.response?.status === 403
                ? 'Je mag geen route in de planning van deze monteur zetten.'
                : messageFrom(exception);
        }
    } finally {
        assigning.value = false;
    }
};

watch(home, (person) => {
    if (person?.may_assign) {
        assignee.value = person.id;
    }
});

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
