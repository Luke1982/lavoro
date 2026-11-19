/**
 * One machine or storing picked for a new werkbon: what it is, whose it is and where it
 * stands. A werkbon is for one customer and one address, so both travel with every pick;
 * a location of null is the customer's bezoekadres, an address of its own.
 *
 * Every picker builds its picks here. Checkboxes match a pick by its whole shape, so a
 * pick built with one field more or less elsewhere would never show as ticked.
 */
export const workPick = (id, customerId, locationId = null) => ({
    id,
    customer_id: customerId,
    location_id: locationId ?? null,
})

/** Stands in for "every address of the customer" where a location id is asked for. */
export const EVERY_ADDRESS = 'every'

/**
 * Whether these picks can become one werkbon: 'no' with no machine picked, 'diffCustomers'
 * or 'diffLocations' when they span more than one, otherwise 'yes'.
 */
export function workSelectionState(machines, tickets) {
    if (machines.length === 0) {
        return 'no'
    }
    const picks = [...machines, ...tickets]
    if (new Set(picks.map(pick => pick.customer_id)).size > 1) {
        return 'diffCustomers'
    }
    return new Set(picks.map(pick => pick.location_id ?? null)).size > 1 ? 'diffLocations' : 'yes'
}

const has = (selection, pick) => selection.some(picked => picked.id === pick.id)

/** How many of these picks are in the selection. */
export const countPicked = (selection, picks) => picks.filter(pick => has(selection, pick)).length

/** The selection with these picks added, each once. */
export const withPicks = (selection, picks) => [...selection, ...picks.filter(pick => !has(selection, pick))]

/** The selection with these picks taken out. */
export const withoutPicks = (selection, picks) => selection.filter(picked => !has(picks, picked))
