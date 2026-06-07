import { ref } from 'vue';
import { registerPlugin } from '@capacitor/core';
import { useCapacitor } from './useCapacitor.js';

const LocationTracker = registerPlugin('LocationTracker');

const is_tracking = ref(false);

export function useLocationTracker() {
    const { is_native } = useCapacitor();

    async function start() {
        if (!is_native || is_tracking.value) return;

        // The native foreground service collects GPS and POSTs to the server
        // independently of the WebView, so it keeps running when the app is
        // backgrounded. It needs the server origin and reads session cookies
        // from the shared native cookie store for authentication.
        await LocationTracker.start({ serverUrl: window.location.origin });
        is_tracking.value = true;
    }

    async function stop() {
        if (!is_tracking.value) return;
        await LocationTracker.stop();
        is_tracking.value = false;
    }

    return { is_tracking, start, stop };
}
