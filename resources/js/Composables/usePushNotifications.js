import { registerPlugin } from '@capacitor/core'
import axios from 'axios'
import { useCapacitor } from './useCapacitor.js'

/**
 * Pushberichten in de Android-app, via Firebase. Los van useWebPush.js: in de
 * browser bezorgt de service worker, hier tekent de app ze zelf
 * (LavoroMessagingService), knoppen en al. Een tik daarop komt binnen als
 * appUrlOpen en loopt via useDeepLinks.
 */
const PushSupport = registerPlugin('PushSupport')

/**
 * De aanmelding van deze app-sessie: lopend, gelukt, of null als er geen is.
 * Eén belofte voor iedereen die vraagt, zodat een layout die opnieuw opbouwt de
 * luisteraars niet dubbel krijgt, en afmelden wacht tot aanmelden klaar is.
 */
let registration = null
let token = null
let csrf_ready = false

async function ensure_csrf() {
    if (csrf_ready) return
    await axios.get('/sanctum/csrf-cookie', { timeout: 5000 })
    csrf_ready = true
}

/**
 * Geeft het token af aan wie nu ingelogd is. De server hangt het aan die persoon
 * en onthoudt het in de sessie, zodat afmelden het daar ook weghaalt. Lukt het
 * niet, dan lukt het bij de volgende keer.
 */
function hand_in(value, platform) {
    token = value

    return ensure_csrf()
        .then(() => axios.post('/api/device-tokens', { token: value, platform }))
        .catch(() => {})
}

/**
 * Zonder Firebase-project in de build laat registreren de app native vallen,
 * buiten bereik van elke catch hier. Een app zonder deze plugin (iOS) heeft het
 * evenmin.
 */
async function push_available() {
    try {
        return (await PushSupport.available()).available === true
    } catch {
        return false
    }
}

async function attach(platform) {
    if (!(await push_available())) return false

    const { PushNotifications } = await import('@capacitor/push-notifications')

    const permission = await PushNotifications.requestPermissions()
    if (permission.receive !== 'granted') return false

    /** Voor register(): het token kan binnenkomen voordat die terugkomt. */
    await PushNotifications.addListener('registration', ({ value }) => hand_in(value, platform))

    await PushNotifications.addListener('registrationError', (error) => {
        console.error('Push registration failed:', error.error)
    })

    await PushNotifications.register()

    return true
}

export function usePushNotifications() {
    const { is_native, platform } = useCapacitor()

    /**
     * Geeft terug of deze telefoon nu berichten kan ontvangen. Wat mislukt mag
     * de volgende keer opnieuw.
     */
    function register() {
        if (!is_native) return Promise.resolve(false)

        /**
         * Al aangemeld, maar misschien voor iemand anders: wie na een verlopen
         * sessie opnieuw inlogt, heeft niet eerst afgemeld. Opnieuw afgeven hangt
         * het token aan wie er nu is.
         */
        if (registration) {
            registration.then((registered) => registered && token && hand_in(token, platform))

            return registration
        }

        registration = attach(platform)
            .catch((e) => {
                console.error('Push registration failed:', e)
                return false
            })
            .then((registered) => {
                if (!registered) registration = null
                return registered
            })

        return registration
    }

    /**
     * Voor het afmelden, zolang er nog een sessie is. Blijft het token staan, dan
     * krijgt wie deze telefoon daarna gebruikt de afspraken, adressen en
     * telefoonnummers van wie hier het laatst ingelogd was. De server haalt het
     * bij het afmelden ook zelf weg; dit is de kant van de telefoon.
     *
     * Firebase vergeet het token ook. Wie daarna inlogt, zonder de app opnieuw te
     * starten, registreert opnieuw en krijgt een eigen token. Een slechte
     * verbinding houdt het afmelden niet op.
     */
    async function unregister() {
        if (!is_native || !(await registration)) return

        const forgotten = token
        registration = null
        token = null

        const { PushNotifications } = await import('@capacitor/push-notifications')

        const steps = await Promise.allSettled([
            PushNotifications.removeAllListeners(),
            PushNotifications.unregister(),
            forgotten && ensure_csrf().then(() => axios.delete('/api/device-tokens', {
                data: { token: forgotten, platform },
                timeout: 5000,
            })),
        ])

        steps.filter((step) => step.status === 'rejected')
            .forEach((step) => console.error('Push unregister failed:', step.reason))
    }

    /**
     * Android houdt berichten vast om de batterij te sparen, en een herinnering
     * die pas komt als je de telefoon weer oppakt is te laat. De app legt dat uit
     * en vraagt Android om een uitzondering; wie al uitgezonderd is merkt niets.
     */
    async function askBatteryExemption() {
        if (!is_native || !(await registration)) return

        await PushSupport.requestBatteryExemption()
    }

    return { register, unregister, askBatteryExemption }
}
