// Shared drag payload helpers for dropping service orders onto the resource planner.
export const PLANNER_DND_MIME = 'application/x-planner-payload'

/**
 * Write a service order onto a native drag event so the planner can turn it
 * into an event on drop. Duration is left unset so the drop uses the planner's
 * configured default minutes (the same value the drag ghost previews).
 */
export function setServiceOrderDragData(e, so) {
    const payload = {
        name: so.description ? String(so.description).slice(0, 255) : `Werkbon #${so.id}`,
        description: so.description || '',
        eventable_type: '\\App\\Models\\ServiceOrder',
        eventable_id: so.id,
        customer_id: so.customer_id ?? so.customer?.id ?? null,
    }
    e.dataTransfer.setData(PLANNER_DND_MIME, JSON.stringify(payload))
    e.dataTransfer.effectAllowed = 'copy'
}
