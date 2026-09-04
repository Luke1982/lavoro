/**
 * Eén plek waar centen euro's worden. Het scherm rekende dat op drie plekken
 * zelf om, en net iets anders.
 */
export const euro = (cents) => new Intl.NumberFormat('nl-NL', {
    style: 'currency',
    currency: 'EUR',
}).format((cents ?? 0) / 100)

/** Voor een invoerveld: punt als scheidingsteken, leeg als er niets is. */
export const euroInput = (cents) => (cents === null || cents === undefined || cents === 0
    ? ''
    : (cents / 100).toFixed(2))
