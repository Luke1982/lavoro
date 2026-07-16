import { ref } from 'vue';
import axios from 'axios';

/**
 * Fetches a customer's locations for a picker, with an out-of-order guard so
 * that rapidly switching customers can never leave stale options bound.
 * Returns raw location objects ({ id, name, address, postal_code, city }); each
 * consumer maps them as needed (ComboBox uses id + name).
 */
export function useCustomerLocations() {
    const locations = ref([]);
    let request_seq = 0;

    const load = async (customerId) => {
        const seq = ++request_seq;

        if (!customerId) {
            locations.value = [];
            return;
        }

        try {
            const { data } = await axios.get(`/combo/customers/${customerId}/locations`);
            if (seq === request_seq) {
                locations.value = data;
            }
        } catch {
            if (seq === request_seq) {
                locations.value = [];
            }
        }
    };

    return { locations, load };
}
