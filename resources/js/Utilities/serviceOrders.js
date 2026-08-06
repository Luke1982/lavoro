import { router, usePage } from '@inertiajs/vue3'

export function serviceOrderIsPlannable(serviceorder) {
    const stage = serviceorder?.service_order_stage
    return !!stage?.is_plannable_state && !stage?.is_planned_state
}

/**
 * Eén plek voor het verzetten van de fase: dezelfde PATCH en dezelfde
 * foutafhandeling, of het nu vanuit de werkbonlijst of het contextmenu gebeurt.
 * De backend bepaalt of de verplaatsing mag; een afwijzing komt terug als flash.
 */
export function patchServiceOrderStage(serviceorder, stage_id) {
    router.patch(`/serviceorders/${serviceorder.id}`, {
        customer_id: serviceorder.customer_id ?? serviceorder.customer?.id,
        service_order_stage_id: stage_id,
    }, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            const message = errors.service_order_stage_id || Object.values(errors)[0]
            if (message) usePage().props.flash.error = message
        },
    })
}
