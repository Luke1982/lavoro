import { computed, ref, watch } from 'vue'
import { onKeyStroke } from '@vueuse/core'

/**
 * Choosing several items with a click, the way a file manager does. Once choosing,
 * a click on an item chooses it and a click on a chosen one leaves it out again;
 * Shift adds every item between the last one clicked and this one. With a mouse,
 * Ctrl, Cmd or Shift on an item starts choosing without a button. Escape stops.
 *
 * `orderedIds` gives the items in the order they are shown, which is what a Shift
 * range runs along. An item that disappears falls out of the choice.
 *
 *     const selection = useClickSelection(() => photos.value.map(photo => photo.id))
 *     <div @click.capture="selection.click($event, photo.id) && $event.stopPropagation()">
 */
export function useClickSelection(orderedIds) {
    const active = ref(false)
    const selectedIds = ref([])
    let anchor = null

    const isSelected = (id) => selectedIds.value.includes(id)
    const allSelected = computed(() => orderedIds().length > 0 && selectedIds.value.length === orderedIds().length)

    function start(id = null) {
        active.value = true
        selectedIds.value = id === null ? [] : [id]
        anchor = id
    }

    function stop() {
        active.value = false
        selectedIds.value = []
        anchor = null
    }

    function toggleAll() {
        selectedIds.value = allSelected.value ? [] : [...orderedIds()]
    }

    function toggle(id) {
        selectedIds.value = isSelected(id) ? selectedIds.value.filter((other) => other !== id) : [...selectedIds.value, id]
        anchor = id
    }

    function extendTo(id) {
        const order = orderedIds()
        const from = order.indexOf(anchor)
        const to = order.indexOf(id)
        if (from === -1 || to === -1) return toggle(id)
        selectedIds.value = [...new Set([...selectedIds.value, ...order.slice(Math.min(from, to), Math.max(from, to) + 1)])]
    }

    /**
     * A click on an item. Says whether it was a choosing click, which the caller
     * then keeps from opening the item.
     */
    function click(event, id) {
        const modified = event.ctrlKey || event.metaKey || event.shiftKey
        if (!active.value && !modified) return false
        event.preventDefault()
        if (!active.value) start(id)
        else if (event.shiftKey && anchor !== null) extendTo(id)
        else toggle(id)

        return true
    }

    watch(orderedIds, (ids) => {
        selectedIds.value = selectedIds.value.filter((id) => ids.includes(id))
    })

    onKeyStroke('Escape', (event) => {
        if (active.value && !event.defaultPrevented) stop()
    })

    return { active, selectedIds, isSelected, allSelected, start, stop, toggleAll, click }
}
