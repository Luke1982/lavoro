import { ref } from 'vue';
import { useCapacitor } from './useCapacitor.js';

const update_url = ref(null);
let checked = false;

export function useAppUpdate() {
    const { is_native } = useCapacitor();

    async function check() {
        if (!is_native || checked) return;
        checked = true;

        try {
            const { App } = await import('@capacitor/app');
            const info = await App.getInfo();

            const res = await fetch('/app/version');
            if (!res.ok) return;

            const latest = await res.json();
            if (parseInt(latest.build) > parseInt(info.build)) {
                update_url.value = latest.download_url;
            }
        } catch {
            // Non-fatal — version check fails silently.
        }
    }

    return { update_url, check };
}
