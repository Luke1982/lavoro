import { ref, toValue, watch } from 'vue'
import axios from 'axios'

/**
 * De afspraken van een werkbon zoals de gebruiker ze mag zien, bij te werken
 * nadat iemand tijden registreert of een afspraak annuleert zonder de hele
 * pagina te herladen.
 *
 * @param {import('vue').MaybeRefOrGetter<number>} serviceOrderId
 * @param {import('vue').MaybeRefOrGetter<Array>} initial
 */
export function useServiceOrderEvents(serviceOrderId, initial) {
    const events = ref(toValue(initial))

    watch(() => toValue(initial), (value) => { events.value = value })

    /** @returns {Promise<Array<string>>} who on this werkbon still has no times filled in */
    async function refresh() {
        const { data } = await axios.get('/api/serviceorders/' + toValue(serviceOrderId) + '/event-widget')
        events.value = data.events

        return data.users_missing_times
    }

    return { events, refresh }
}
