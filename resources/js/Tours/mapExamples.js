import L from 'leaflet'

/**
 * What the map rondleiding makes up to show: a few addresses picked as a person
 * would, and a route along them.
 */

/** Roads are longer than the line between two points, and a van is not always on the motorway. */
const DETOUR = 1.3
const METERS_PER_SECOND = 60000 / 3600

/** The address left over may well fit in the day, so its reason says what it is: an example. */
const LEFT_OVER_REASON = 'Voorbeeld: zo zie je een adres dat niet meer in de werkdag past.'

/** Where an address is. Its coordinates arrive as text, which Leaflet would take for an area. */
const pointOf = ({ lat, lon }) => L.latLng(Number(lat), Number(lon))

/** The `count` addresses in sight closest together: the one nearest the middle of the map, and its neighbours. */
export function addressesClose(items, map, count) {
    const bounds = map.getBounds()
    const placed = items.filter((item) => item.lat != null && bounds.contains(pointOf(item)))
    const nearest = (from) => [...placed].sort((a, b) => from.distanceTo(pointOf(a)) - from.distanceTo(pointOf(b)))
    const [first] = nearest(map.getCenter())

    return first ? nearest(pointOf(first)).slice(0, count) : []
}

/**
 * A route along the stops on the screen, shaped as the routing server answers,
 * made up instead of asked: the server counts every question, and a rondleiding
 * only shows what an answer looks like. The stops go in the order they were
 * picked along straight lines at an unhurried pace, from `start` and back. With
 * three or more, the last one is left over, so the screen also shows what does
 * not fit.
 */
export function exampleRoute(stops, start) {
    const kept = stops.length > 2 ? stops.slice(0, -1) : stops
    const points = [start, ...kept.map(({ item }) => item), start].map(pointOf)
    const arrivals = []
    let clock = 0
    let meters = 0

    points.slice(1).forEach((point, index) => {
        const leg = points[index].distanceTo(point) * DETOUR
        meters += leg
        clock += leg / METERS_PER_SECOND
        if (index < kept.length) {
            arrivals.push(clock)
            clock += (kept[index].minutes ?? 0) * 60
        }
    })

    return {
        order: kept.map((_, index) => index),
        arrivals,
        finish: clock,
        distance: meters,
        duration: meters / METERS_PER_SECOND,
        path: points.map(({ lat, lng }) => [lat, lng]),
        start: { lat: points[0].lat, lon: points[0].lng },
        unassigned: kept.length < stops.length ? [{ stop: stops.length - 1, reason: LEFT_OVER_REASON }] : [],
    }
}
