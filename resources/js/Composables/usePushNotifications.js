import { useCapacitor } from './useCapacitor.js';
import axios from 'axios';

export function usePushNotifications() {
    const { is_native, platform } = useCapacitor();

    async function register() {
        if (!is_native) return;

        const { PushNotifications } = await import('@capacitor/push-notifications');

        const permission = await PushNotifications.requestPermissions();
        if (permission.receive !== 'granted') return;

        await PushNotifications.register();

        PushNotifications.addListener('registration', async (token) => {
            try {
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
            await axios.delete('/api/device-tokens', { data: { token, platform } });
        } catch {
            // Best-effort.
        }
    }

    return { register, unregister };
}
