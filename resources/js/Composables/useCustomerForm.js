import { useForm } from '@inertiajs/vue3'

/**
 * A new customer, as the customers page and the machine forms both make one.
 * Only the name is required; the rest can follow on the customer itself.
 */
export function useCustomerForm() {
    return useForm({
        name: '',
        email: '',
        phone: '',
        address: '',
        postal_code: '',
        city: '',
        country: '',
        location_code: '',
    })
}
