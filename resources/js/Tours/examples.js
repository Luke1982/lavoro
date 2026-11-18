/**
 * Made-up records the rondleidingen type in and show, and never save. A brand
 * and model nobody sells, so no picker ever finds a real one to offer instead
 * of the "Nieuw …" row the step is about. The storyboards say these words, and
 * their check holds them to it.
 */
export const NEW_PRODUCT = Object.freeze({ brand: 'Polarix', model: 'PX-12 Hybride' })

/** The id a made-up record carries on the screen: never a number, so never a real one. */
const EXAMPLE_ID = 'rondleiding'

/**
 * Shows a made-up record picked in a picker, the way making it would leave the
 * form: `record` added to the picker's `options` and chosen in `form[field]`.
 * Returns how to take it away again.
 */
export function pretendPicked(options, form, field, record) {
    const previous = form[field]
    options.value = [...options.value, { ...record, id: EXAMPLE_ID }]
    form[field] = EXAMPLE_ID

    return () => {
        form[field] = previous
        options.value = options.value.filter(option => option.id !== EXAMPLE_ID)
    }
}
