import { useCapacitor } from './useCapacitor.js';
import axios from 'axios';

let registered = false;
let csrf_ready = false;

async function ensure_csrf() {
    if (csrf_ready) return;
    await axios.get('sanctum/csrf-cookie');
    csrf_ready = true;
}

export function usePushNotifications() {
    const { is_native, platform } = useCapacitor();

    async function register() {
        if (!is_native || registered) return;

        const { PushNotifications } = await import('@capacitor/push-notifications');

        const permission = await PushNotifications.requestPermissions();
        if (permission.receive !== 'granted') return;

        try {
            await PushNotifications.register();
        } catch (e) {
            // Firebase not configured (no google-services.json) — skip push, never block callers.
            console.error('PushNotifications.register failed:', e);
            return;
        }
        registered = true;

        PushNotifications.addListener('registration', async (token) => {
            try {
                await ensure_csrf();
                await axios.post('/api/device-tokens', { token: token.value, platform });
            } catch {
                // Non-fatal — retried on next app launch.
            }
        });

        PushNotifications.addListener('registrationError', (err) => {
            console.error('Push registration failed:', err.error);
        });

        PushNotifications.addListener('pushNotificationReceived', (notification) => {
            // App is foregrounded — notification arrives silently.
            // A future iteration can forward this to GlobalNotification.vue.
            console.info('Push (foreground):', notification.title);
        });

        PushNotifications.addListener('pushNotificationActionPerformed', (action) => {
            const data = action.notification.data;
            if (data?.type === 'service_order_assigned' && data?.id) {
                window.location.href = `/serviceorders/${data.id}`;
            }
        });
    }

    async function unregister(token) {
        if (!is_native) return;
        try {
            await ensure_csrf();
            await axios.delete('/api/device-tokens', { data: { token, platform } });
        } catch {
            // Best-effort.
        }
    }

    return { register, unregister };
}
