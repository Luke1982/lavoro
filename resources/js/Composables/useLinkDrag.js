import { ref } from 'vue'

/**
 * Dragging one card onto another to link them. While dragging, a line follows the
 * pointer from the card being dragged; a card it may be linked to lights up, and
 * dropping on it hands both cards to `onLink`. Drags that start inside an opened
 * card belong to that card's own reordering and are left alone.
 */
export function useLinkDrag(board, { canStart, canLink, onLink }) {
    const drag = ref(null)
    const dropKey = ref(null)

    const cardAt = event => event.target.closest?.('[data-card]')?.dataset.card ?? null

    function point(event) {
        const origin = board.value.getBoundingClientRect()

        return { x: event.clientX - origin.left, y: event.clientY - origin.top }
    }

    function start(event) {
        const key = event.target.closest?.('[data-contents]') ? null : cardAt(event)

        if (!key || !canStart(key)) return

        event.dataTransfer.setData('text/plain', key)
        event.dataTransfer.effectAllowed = 'link'
        drag.value = { from: key, ...point(event) }
    }

    function over(event) {
        if (!drag.value) return

        drag.value = { from: drag.value.from, ...point(event) }

        const key = cardAt(event)
        dropKey.value = key && canLink(drag.value.from, key) ? key : null

        if (dropKey.value) {
            event.preventDefault()
            event.dataTransfer.dropEffect = 'link'
        }
    }

    function drop(event) {
        event.preventDefault()

        const [from, to] = [drag.value?.from, dropKey.value]

        end()

        if (from && to) onLink(from, to)
    }

    function end() {
        drag.value = null
        dropKey.value = null
    }

    return { drag, dropKey, point, handlers: { dragstart: start, dragover: over, drop, dragend: end } }
}
