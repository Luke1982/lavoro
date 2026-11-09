import L from 'leaflet';

/**
 * The pin every address map draws: a drop whose point sits on the address, a
 * number in the head (a dot when there is none), and optionally a badge on the
 * shoulder. A chosen pin is the same drop, larger and outlined dark, so a route
 * stands out from the addresses around it without moving off its own.
 *
 * Only a whole number goes in the head: the icon is raw HTML. The drop carries
 * z-index 0 inline because leaflet.css lifts every svg in the map to 200, which
 * would paint the drop over its own badge. Colours come in as CSS, mostly the
 * --map-* variables from app.css, so they are set through style: an SVG
 * attribute like fill="" does not reliably take a var().
 */
export const PIN_PATH = 'M14 1.5C7.1 1.5 1.5 7 1.5 13.8 1.5 23 14 36.5 14 36.5S26.5 23 26.5 13.8C26.5 7 20.9 1.5 14 1.5Z';

export const SHIELD_PATH = 'M6 1 2 2.5v3c0 2.4 1.7 4.6 4 5.5 2.3-.9 4-3.1 4-5.5v-3L6 1Z';

export const BADGE_COLOR = 'var(--map-contract)';

const SIZES = {
    plain: { width: 28, height: 38, outline: 'stroke-white' },
    chosen: { width: 36, height: 49, outline: 'stroke-gray-900' },
};

const CLUSTER = { size: 34, hole: 24 };

/** How far above its point the head of a plain pin sits, in pixels. */
const PIN_HEAD_HEIGHT = 23;

const BADGE = '<span class="absolute -top-1 -right-1.5 flex h-4 w-4 items-center justify-center rounded-full border border-white"'
    + ` style="background:${BADGE_COLOR}"><svg viewBox="0 0 12 12" class="h-2.5 w-2.5 fill-white"><path d="${SHIELD_PATH}"/></svg></span>`;

export const pinIcon = ({ color, number = null, badge = false, chosen = false }) => {
    const { width, height, outline } = chosen ? SIZES.chosen : SIZES.plain;
    const head = Number.isInteger(number)
        ? `<text x="14" y="14" text-anchor="middle" dominant-baseline="central" font-size="11" font-weight="700" class="fill-white">${number}</text>`
        : '<circle cx="14" cy="13.8" r="4" class="fill-white"/>';

    return L.divIcon({
        className: 'custom-marker',
        html: `<div class="relative drop-shadow" style="width:${width}px;height:${height}px">`
            + '<svg viewBox="0 0 28 38" class="absolute inset-0 h-full w-full" style="z-index:0">'
            + `<path d="${PIN_PATH}" class="${outline}" style="fill:${color}" stroke-width="2"/>${head}</svg>`
            + (badge ? BADGE : '')
            + '</div>',
        iconSize: [width, height],
        iconAnchor: [width / 2, height - 1],
        popupAnchor: [0, 4 - height],
    });
};

/**
 * A ring with each colour's share of `colors`, laid out in the order of
 * `order` -- the legend's, most pressing first -- so red always starts at the
 * top. A colour missing from `order` comes last.
 */
export const ringBackground = (colors, order) => {
    const rank = (color) => {
        const position = order.indexOf(color);
        return position === -1 ? order.length : position;
    };
    const counts = new Map();
    [...colors].sort((a, b) => rank(a) - rank(b)).forEach((color) => counts.set(color, (counts.get(color) ?? 0) + 1));

    let from = 0;
    const segments = [...counts].map(([color, count]) => {
        const to = from + (count / colors.length) * 100;
        const segment = `${color} ${from}% ${to}%`;
        from = to;
        return segment;
    });
    return `conic-gradient(${segments.join(',')})`;
};

/**
 * Addresses that would overlap at this zoom, as one disc: the total of their
 * numbers in the middle, their colours in the ring, and the badge when any of
 * them carries it. The disc floats at the height of a pin's head, so it lines
 * up with the pins around it instead of hiding under them.
 */
export const clusterIcon = ({ colors, order, number, badge = false }) => L.divIcon({
    className: 'custom-marker',
    html: '<div class="relative flex items-center justify-center rounded-full border-2 border-white drop-shadow"'
        + ` style="width:${CLUSTER.size}px;height:${CLUSTER.size}px;background:${ringBackground(colors, order)}">`
        + '<span class="flex items-center justify-center rounded-full bg-white text-[11px] font-bold text-gray-900"'
        + ` style="width:${CLUSTER.hole}px;height:${CLUSTER.hole}px">${Number.isInteger(number) ? number : ''}</span>`
        + (badge ? BADGE : '')
        + '</div>',
    iconSize: [CLUSTER.size, CLUSTER.size],
    iconAnchor: [CLUSTER.size / 2, CLUSTER.size / 2 + PIN_HEAD_HEIGHT],
});
