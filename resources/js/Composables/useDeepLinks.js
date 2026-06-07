import { useCapacitor } from './useCapacitor.js';

export function useDeepLinks() {
    const { is_native } = useCapacitor();

    async function init() {
        if (!is_native) return;

        const { App } = await import('@capacitor/app');

        App.addListener('appUrlOpen', (event) => {
            try {
                const url = new URL(event.url);
                // Only follow links into the server this app is bound to;
                // same-origin navigation keeps the Capacitor bridge intact.
                if (url.origin === window.location.origin) {
                    const target = url.pathname + url.search + url.hash;
                    if (target && target !== window.location.pathname + window.location.search + window.location.hash) {
                        window.location.href = target;
                    }
                }
            } catch {
                // Malformed URL — ignore.
            }
        });
    }

    return { init };
}
