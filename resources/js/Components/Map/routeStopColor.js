/**
 * One colour per kind of stop, the same in the list and on the map: grey when
 * it does not fit in the day, red when urgent, blue otherwise.
 */
export const stopColor = (stop) => {
    if (stop.fits === false) {
        return 'var(--map-route-left-over)';
    }
    return stop.urgent ? 'var(--map-route-urgent)' : 'var(--map-route)';
};
