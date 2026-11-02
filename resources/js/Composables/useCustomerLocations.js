import { computed, ref } from 'vue';
import axios from 'axios';

/**
 * Fetches a customer's locations for a picker, with an out-of-order guard so
 * that rapidly switching customers can never leave stale options bound.
 * Returns raw location objects ({ id, name, address, postal_code, city }); each
 * consumer maps them as needed (ComboBox uses id + name).
 *
 * `message` is what the picker says when it has nothing to offer, and it is
 * owned here rather than at every call site: an empty list and a refused
 * request look identical to the person staring at the box, and this is the only
 * place that knows which of the two it was. A picker that goes quiet instead of
 * saying so cost us a customer report about a location they could not select --
 * the request was refused for want of a permission, and nothing said a word.
 */
export function useCustomerLocations() {
    const locations = ref([]);
    const state = ref('idle');
    let request_seq = 0;

    const message = computed(() => {
        if (state.value === 'loading') return 'Locaties worden geladen…';

        if (state.value === 'forbidden') {
            return 'Je mag de locaties van deze klant niet opvragen. Vraag een beheerder om het recht ‘Locaties bekijken’.';
        }

        if (state.value === 'failed') {
            return 'De locaties konden niet worden opgehaald. Probeer het opnieuw of herlaad de pagina.';
        }

        if (state.value === 'idle') return 'Kies eerst een klant.';

        return locations.value.length === 0
            ? 'Deze klant heeft nog geen locaties. Je maakt ze aan bij de klant, onder Locaties.'
            : '';
    });

    const load = async (customerId) => {
        const seq = ++request_seq;

        if (!customerId) {
            locations.value = [];
            state.value = 'idle';

            return;
        }

        state.value = 'loading';

        try {
            const { data } = await axios.get(`/combo/customers/${customerId}/locations`);

            if (seq === request_seq) {
                locations.value = data;
                state.value = 'ready';
            }
        } catch (error) {
            if (seq !== request_seq) return;

            locations.value = [];
            state.value = error?.response?.status === 403 ? 'forbidden' : 'failed';
        }
    };

    return { locations, load, message, state };
}
