import { ref } from 'vue';
import { registerPlugin } from '@capacitor/core';
import { usePage } from '@inertiajs/vue3';
import { useCapacitor } from './useCapacitor.js';
import axios from 'axios';

const LocationTracker = registerPlugin('LocationTracker');

const is_tracking = ref(false);

// Web-only module-level state (singleton — one tracker per browser session)
let watch_id = null;
let ping_buffer = [];
let flush_interval_id = null;
let csrf_fetched = false;
let is_flushing = false;

function js_day_to_iso(day) {
    // JS getDay() returns 0=Sun … 6=Sat; ISO weekday is 1=Mon … 7=Sun
    return day === 0 ? 7 : day;
}

function is_within_window(settings) {
    if (!settings) return true;
    const now = new Date();
    const iso_day = js_day_to_iso(now.getDay());
    const days = Array.isArray(settings.days)
        ? settings.days.map(Number)
        : String(settings.days).split(',').map(Number);
    if (!days.includes(iso_day)) return false;
    const hhmm = now.toTimeString().slice(0, 5); // "HH:MM"
    return hhmm >= settings.start && hhmm < settings.end;
}

async function ensure_csrf() {
    if (csrf_fetched) return;
    await axios.get('/sanctum/csrf-cookie');
    csrf_fetched = true;
}

async function flush_buffer() {
    if (ping_buffer.length === 0 || is_flushing) return;
    is_flushing = true;
    const pings = ping_buffer.splice(0);
    try {
        await ensure_csrf();
        await axios.post('/api/location/pings', { pings });
    } catch {
        ping_buffer.unshift(...pings);
    } finally {
        is_flushing = false;
    }
}

function start_watch() {
    if (watch_id !== null) return;
    watch_id = navigator.geolocation.watchPosition(
        (pos) => {
            ping_buffer.push({
                lat:         pos.coords.latitude,
                lng:         pos.coords.longitude,
                accuracy:    pos.coords.accuracy ?? null,
                speed:       pos.coords.speed ?? null,
                heading:     pos.coords.heading ?? null,
                recorded_at: new Date(pos.timestamp).toISOString(),
            });
            if (ping_buffer.length >= 10) flush_buffer();
        },
        (err) => {
            if (err.code === err.PERMISSION_DENIED) {
                console.warn('GPS: locatiepermissie geweigerd');
                stop_watch();
                clearInterval(flush_interval_id);
                flush_interval_id = null;
                is_tracking.value = false;
            }
        },
        { enableHighAccuracy: true, timeout: 30000, maximumAge: 0 },
    );
}

function stop_watch() {
    if (watch_id === null) return;
    navigator.geolocation.clearWatch(watch_id);
    watch_id = null;
}

export function useLocationTracker() {
    const { is_native } = useCapacitor();

    async function start() {
        if (is_tracking.value) return;

        if (is_native) {
            // The native foreground service collects GPS and POSTs to the server
            // independently of the WebView, so it keeps running when the app is
            // backgrounded. It needs the server origin and reads session cookies
            // from the shared native cookie store for authentication.
            await LocationTracker.start({ serverUrl: window.location.origin });
            is_tracking.value = true;
            return;
        }

        if (!navigator.geolocation) return;

        is_tracking.value = true;

        // Every 60 s: check window and flush buffer
        flush_interval_id = setInterval(() => {
            const settings = usePage().props.location_tracking;
            if (is_within_window(settings)) {
                start_watch();
            } else {
                stop_watch();
            }
            flush_buffer();
        }, 60_000);

        // Start immediately if inside the window
        if (is_within_window(usePage().props.location_tracking)) {
            start_watch();
        }
    }

    async function stop() {
        if (!is_tracking.value) return;

        if (is_native) {
            await LocationTracker.stop();
        } else {
            stop_watch();
            clearInterval(flush_interval_id);
            flush_interval_id = null;
            csrf_fetched = false;
            await flush_buffer();
        }

        is_tracking.value = false;
    }

    return { is_tracking, start, stop };
}
