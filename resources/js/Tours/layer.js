/**
 * The one mark the shared building blocks look for to leave the rondleiding
 * alone. A popover, a context menu or a dialog that closes on a click elsewhere
 * would otherwise close the moment somebody clicks "Volgende" — on the very
 * thing the rondleiding just opened to show them.
 */
export const TOUR_LAYER_SELECTOR = '[data-tour-layer]'

export function isInsideTourLayer(node) {
    return node instanceof Element && node.closest(TOUR_LAYER_SELECTOR) !== null
}
