const setValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set

function enter(input, text) {
    setValue.call(input, text)
    input.dispatchEvent(new Event('input', { bubbles: true }))
}

/**
 * Types into a real field, so whatever listens to it reacts as it does to a
 * person: a picker searches and opens its list with "Nieuw …" at the bottom.
 * Returns how to empty it again, or null when there is no field to type in.
 *
 * The field never gets the focus. A picker that loses the focus while its list
 * is open takes the highlighted row, and that row is "Nieuw …": a click on the
 * rondleiding's own buttons would make the very record it only shows. Escape
 * closes the list without taking anything.
 */
export function typeInto(input, text) {
    if (!input) return null
    enter(input, text)

    return () => {
        enter(input, '')
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))
    }
}
