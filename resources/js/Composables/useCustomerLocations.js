import { computed, ref } from 'vue';
import axios from 'axios';

/**
 * What the picker says when it has nothing to offer. The wording lives here
 * because this is the only place that can tell an empty list from a refused
 * request, and to the person at the box the two look the same -- a picker that
 * went quiet about a refusal cost us a customer report. 'ready' has no entry: a
 * list with something in it says nothing.
 */
const messages = {
    idle: 'Kies eerst een klant.',
    loading: 'Locaties worden geladen…',
    empty: 'Deze klant heeft nog geen locaties. Je maakt ze aan bij de klant, onder Locaties.',
    forbidden: 'Je mag de locaties van deze klant niet opvragen. Vraag een beheerder om het recht ‘Locaties bekijken’.',
    failed: 'De locaties konden niet worden opgehaald. Probeer het opnieuw of herlaad de pagina.',
};

/**
 * Fetches a customer's locations for a picker. Returns the options as the
 * endpoint hands them over ({ id, name, address, postal_code, city, search });
 * each consumer maps them as needed (ComboBox matches on name + search).
 */
export function useCustomerLocations() {
    const locations = ref([]);
    const state = ref('idle');
    const message = computed(() => messages[state.value] ?? '');

    let requestSeq = 0;

    const load = async (customerId) => {
        const seq = ++requestSeq;

        /** Never leave the previous customer's locations on offer. */
        locations.value = [];

        if (!customerId) {
            state.value = 'idle';

            return;
        }

        state.value = 'loading';

        try {
            const { data } = await axios.get(`/combo/customers/${customerId}/locations`);

            if (seq !== requestSeq) return;

            locations.value = data;
            state.value = data.length === 0 ? 'empty' : 'ready';
        } catch (error) {
            if (seq !== requestSeq) return;

            state.value = error?.response?.status === 403 ? 'forbidden' : 'failed';
        }
    };

    return { locations, load, message };
}
