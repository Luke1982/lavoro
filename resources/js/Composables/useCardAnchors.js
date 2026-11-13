import { onBeforeUnmount, onMounted, ref } from 'vue'

/** Where on a card a line attaches: level with the card's title, not its middle, so an opened card keeps its lines at the top. */
const TITLE_OFFSET = 20

/**
 * Where the lines of the checklist editor attach: for every card, a point on its
 * left and right edge, relative to the board. A card scrolled out of its column
 * keeps its lines, ending at the column's edge.
 *
 * Measured from the DOM whenever something can have moved: the board or a card
 * resizing, cards coming and going, or a column scrolling.
 */
export function useCardAnchors(board) {
    const anchors = ref(new Map())
    let frame = null
    let resizeObserver = null
    let mutationObserver = null

    function measure() {
        frame = null

        const root = board.value

        if (!root) return

        const origin = root.getBoundingClientRect()
        const next = new Map()

        root.querySelectorAll('[data-card]').forEach(card => {
            const rect = card.getBoundingClientRect()
            const column = card.closest('[data-scroller]')?.getBoundingClientRect() ?? origin
            const raw = rect.top + Math.min(TITLE_OFFSET, rect.height / 2)
            const y = Math.min(Math.max(raw, column.top), column.bottom) - origin.top

            next.set(card.dataset.card, {
                left: { x: rect.left - origin.left, y },
                right: { x: rect.right - origin.left, y },
            })
        })

        anchors.value = next
    }

    function refresh() {
        if (frame === null) frame = requestAnimationFrame(measure)
    }

    onMounted(() => {
        resizeObserver = new ResizeObserver(refresh)
        resizeObserver.observe(board.value)

        const watchCards = () => board.value?.querySelectorAll('[data-card]').forEach(card => resizeObserver.observe(card))

        mutationObserver = new MutationObserver(() => {
            watchCards()
            refresh()
        })
        mutationObserver.observe(board.value, { childList: true, subtree: true })

        board.value.addEventListener('scroll', refresh, true)
        window.addEventListener('resize', refresh)
        watchCards()
        refresh()
    })

    onBeforeUnmount(() => {
        resizeObserver?.disconnect()
        mutationObserver?.disconnect()
        board.value?.removeEventListener('scroll', refresh, true)
        window.removeEventListener('resize', refresh)

        if (frame !== null) cancelAnimationFrame(frame)
    })

    return anchors
}
