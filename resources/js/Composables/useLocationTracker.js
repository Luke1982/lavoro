import { ref, watch } from 'vue';
import { Preferences } from '@capacitor/preferences';
import { useCapacitor } from './useCapacitor.js';
import { useNetworkStatus } from './useNetworkStatus.js';
import axios from 'axios';

const QUEUE_KEY     = 'lavoro_location_queue';
const LAST_PING_KEY = 'lavoro_last_ping_at';
const MAX_QUEUE     = 200;
const MIN_INTERVAL  = 10 * 60 * 1000; // 10 minutes in ms

const is_tracking = ref(false);
let watcher_id = null;

async function get_queue() {
    const { value } = await Preferences.get({ key: QUEUE_KEY });
    return value ? JSON.parse(value) : [];
}

async function save_queue(queue) {
    await Preferences.set({ key: QUEUE_KEY, value: JSON.stringify(queue) });
}

async function get_last_ping_at() {
    const { value } = await Preferences.get({ key: LAST_PING_KEY });
    return value ? parseInt(value, 10) : 0;
}

async function set_last_ping_at(ts) {
    await Preferences.set({ key: LAST_PING_KEY, value: String(ts) });
}

async function enqueue(ping) {
    const queue = await get_queue();
    queue.push(ping);
    if (queue.length > MAX_QUEUE) queue.splice(0, queue.length - MAX_QUEUE);
    await save_queue(queue);
}

async function flush() {
    const queue = await get_queue();
    if (!queue.length) return;
    try {
        await axios.post('/api/location/pings', { pings: queue });
        await save_queue([]);
    } catch {
        // Keep queue intact; will retry on next reconnect.
    }
}

export function useLocationTracker() {
    const { is_native } = useCapacitor();
    const { is_online } = useNetworkStatus();

    watch(is_online, async (online) => {
        if (online) await flush();
    });

    async function start() {
        if (!is_native || is_tracking.value) return;
        is_tracking.value = true;

        const BackgroundGeolocation = (await import(/* @vite-ignore */ '@capacitor-community/background-geolocation')).default;

        watcher_id = await BackgroundGeolocation.addWatcher(
            {
                backgroundMessage: 'Lavoro volgt uw locatie voor servicebezoeken.',
                backgroundTitle: 'Locatie actief',
                requestPermissions: true,
                stale: false,
                distanceFilter: 50,
            },
            async function (location, error) {
                if (error || !location) return;

                const now  = Date.now();
                const last = await get_last_ping_at();
                if (now - last < MIN_INTERVAL) return;

                await set_last_ping_at(now);
                await enqueue({
                    lat:         location.latitude,
                    lng:         location.longitude,
                    accuracy:    location.accuracy  ?? null,
                    speed:       location.speed     ?? null,
                    heading:     location.bearing   ?? null,
                    recorded_at: new Date(location.time).toISOString(),
                });

                if (is_online.value) await flush();
            }
        );
    }

    async function stop() {
        if (!is_tracking.value) return;
        is_tracking.value = false;

        if (watcher_id !== null) {
            const BackgroundGeolocation = (await import(/* @vite-ignore */ '@capacitor-community/background-geolocation')).default;
            await BackgroundGeolocation.removeWatcher({ id: watcher_id });
            watcher_id = null;
        }
    }

    return { is_tracking, start, stop };
}
