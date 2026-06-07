import { ref } from 'vue';
import { useCapacitor } from './useCapacitor.js';

const is_online = ref(true);
const connection_type = ref('unknown');
let initialized = false;

export function useNetworkStatus() {
    const { is_native } = useCapacitor();

    async function init() {
        if (initialized) return;
        initialized = true;

        if (is_native) {
            const { Network } = await import('@capacitor/network');
            const status = await Network.getStatus();
            is_online.value = status.connected;
            connection_type.value = status.connectionType;

            await Network.addListener('networkStatusChange', (status) => {
                is_online.value = status.connected;
                connection_type.value = status.connectionType;
            });
        } else {
            is_online.value = navigator.onLine;
            window.addEventListener('online', () => { is_online.value = true; });
            window.addEventListener('offline', () => { is_online.value = false; });
        }
    }

    return { is_online, connection_type, init };
}
