import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { useCapacitor } from './useCapacitor.js'

/**
 * Browser meldingen: toestemming vragen en het abonnement bij de server
 * afgeven. Los van usePushNotifications.js, dat over de native app en Firebase
 * gaat — hier doet de browser zelf het bezorgen, via de service worker.
 *
 * De toestand staat buiten de composable, zodat elke plek die dit gebruikt
 * dezelfde toestemming ziet en er niet twee keer om gevraagd wordt.
 */
const permission = ref(supports_notifications() ? Notification.permission : 'unsupported')
const syncing = ref(false)

function supports_notifications() {
    return typeof window !== 'undefined'
        && typeof Notification !== 'undefined'
        && 'serviceWorker' in navigator
        && 'PushManager' in window
}

/**
 * Safari in een privévenster laat localStorage bestaan en gooit bij het
 * schrijven. Onthouden is hier nergens noodzakelijk, dus dat mag mislukken
 * zonder dat iemand een foutmelding ziet.
 */
const storage = {
    get(key) {
        try {
            return window.localStorage?.getItem(key) ?? null
        } catch {
            return null
        }
    },
    set(key, value) {
        try {
            window.localStorage?.setItem(key, value)
        } catch {
            // Niets aan te doen, en niets dat hierop wacht.
        }
    },
    forget(key) {
        try {
            window.localStorage?.removeItem(key)
        } catch {
            // Zie set().
        }
    },
}

const dismissed = ref(typeof window !== 'undefined' && storage.get('push_prompt_dismissed') === '1')

/**
 * De VAPID-sleutel komt als base64url binnen en moet als bytes de browser in.
 */
function url_base64_to_uint8_array(base64_string) {
    const padding = '='.repeat((4 - (base64_string.length % 4)) % 4)
    const base64 = (base64_string + padding).replace(/-/g, '+').replace(/_/g, '/')
    const raw = window.atob(base64)

    return Uint8Array.from([...raw].map(character => character.charCodeAt(0)))
}

/**
 * navigator.serviceWorker.ready blijft eeuwig hangen zolang er geen actieve
 * registratie is — in de native webview, of op een origin waar de browser geen
 * service worker toestaat. Wachten mag, maar niet onbeperkt: het afmelden hangt
 * hieraan, en dat moet altijd doorlopen.
 */
function ready_registration(timeout_ms = 3000) {
    if (!supports_notifications()) return Promise.resolve(null)

    return Promise.race([
        navigator.serviceWorker.ready,
        new Promise(resolve => window.setTimeout(() => resolve(null), timeout_ms)),
    ])
}

/**
 * Oude browsers noemen aesgcm als eerste, terwijl ze aes128gcm ook aankunnen.
 * De nieuwste die er staat is altijd de juiste keuze.
 */
function preferred_content_encoding() {
    const supported = window.PushManager?.supportedContentEncodings ?? ['aes128gcm']

    return supported.includes('aes128gcm') ? 'aes128gcm' : supported[0]
}

function same_key(subscription, key) {
    const existing = subscription?.options?.applicationServerKey

    if (!existing) return false

    const current = new Uint8Array(existing)
    const wanted = url_base64_to_uint8_array(key)

    return current.length === wanted.length && current.every((byte, index) => byte === wanted[index])
}

export function useWebPush() {
    const page = usePage()
    const { is_native } = useCapacitor()

    const vapid_public_key = computed(() => page.props?.push?.vapid_public_key ?? null)

    /**
     * Native draait via Firebase en heeft hier niets te zoeken. Zonder sleutel
     * heeft vragen ook geen zin: een abonnement zou nergens naartoe kunnen.
     */
    const is_supported = computed(() => !is_native && supports_notifications() && !!vapid_public_key.value)

    const can_ask = computed(() => is_supported.value && permission.value === 'default' && !dismissed.value)
    const is_enabled = computed(() => is_supported.value && permission.value === 'granted')

    async function enable() {
        if (!is_supported.value) return false

        permission.value = await Notification.requestPermission()

        if (permission.value !== 'granted') {
            return false
        }

        return await subscribe()
    }

    /**
     * Een bestaand abonnement dat op een oudere sleutel is afgegeven kan nooit
     * meer bezorgd worden, dus dat wordt eerst opgezegd.
     */
    async function subscribe() {
        try {
            const registration = await ready_registration()

            if (!registration) return false

            let subscription = await registration.pushManager.getSubscription()

            if (subscription && !same_key(subscription, vapid_public_key.value)) {
                await subscription.unsubscribe()
                subscription = null
            }

            subscription = subscription ?? await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: url_base64_to_uint8_array(vapid_public_key.value),
            })

            const payload = subscription.toJSON()

            await axios.get('/sanctum/csrf-cookie')
            await axios.post('/pushsubscriptions', {
                endpoint: payload.endpoint,
                keys: payload.keys,
                content_encoding: preferred_content_encoding(),
            })

            storage.set('push_endpoint_synced', payload.endpoint)

            return true
        } catch (error) {
            console.error('Push subscription failed:', error)

            return false
        }
    }

    async function disable() {
        if (is_native || !supports_notifications()) return

        try {
            const registration = await ready_registration()
            const subscription = await registration?.pushManager?.getSubscription()

            if (!subscription) return

            const { endpoint } = subscription.toJSON()

            await subscription.unsubscribe()
            await axios.get('/sanctum/csrf-cookie')
            await axios.delete('/pushsubscriptions', { data: { endpoint } })

            storage.forget('push_endpoint_synced')
        } catch (error) {
            console.error('Push unsubscribe failed:', error)
        }
    }

    /**
     * Wie al toestemming heeft gegeven, hoeft er niet nog eens om gevraagd te
     * worden — maar de server kan het abonnement wel kwijt zijn, bijvoorbeeld
     * nadat een push service het als verlopen afwees. Opnieuw afgeven is
     * onschadelijk: de server werkt de bestaande regel bij.
     *
     * Alleen als het abonnement veranderd is: anders zou elke keer dat de app
     * geladen wordt een schrijfactie opleveren voor iets wat de server al weet.
     */
    async function sync() {
        if (!is_enabled.value || syncing.value) return

        syncing.value = true

        try {
            const registration = await ready_registration()

            if (!registration) return

            const subscription = await registration.pushManager.getSubscription()
            const known = storage.get('push_endpoint_synced')

            if (subscription && same_key(subscription, vapid_public_key.value) && subscription.endpoint === known) {
                return
            }

            await subscribe()
        } catch (error) {
            console.error('Push sync failed:', error)
        } finally {
            syncing.value = false
        }
    }

    function dismiss() {
        dismissed.value = true
        storage.set('push_prompt_dismissed', '1')
    }

    return { is_supported, can_ask, is_enabled, permission, enable, disable, sync, dismiss }
}
