import { router, usePage } from '@inertiajs/vue3'
import { hasAnyPermission, hasPermission } from '@/Utilities/Utilities'

export function serviceOrderIsPlannable(serviceorder) {
    const stage = serviceorder?.service_order_stage
    return !!stage?.is_plannable_state && !stage?.is_planned_state
}

/**
 * Spiegelt ServiceOrderPolicy::list. Wélke werkbonnen iemand te zien krijgt heeft de
 * backend al gefilterd, dus wie er hier een voor zich heeft mag hem ook openen.
 */
export function canReadServiceOrders() {
    return hasAnyPermission(['serviceorder.read', 'serviceorder.read_own'])
}

/**
 * Spiegelt ServiceOrderPolicy::delete: een werkbon die naar de administratie is
 * verstuurd blijft staan, wat iemand verder ook mag.
 */
export function canDeleteServiceOrder(serviceorder) {
    return hasPermission('serviceorder.delete') && !serviceorder.sent_to_administration
}

const DELETE_CONFIRMATION = 'Weet je zeker dat je deze werkbon wilt verwijderen? Alle keuringen en gegevens worden ook verwijderd.'

/**
 * Eén plek voor het verwijderen: dezelfde vraag vooraf en dezelfde route, of het nu
 * vanuit de werkbonlijst of vanuit een contextmenu gebeurt. Die route is de weg langs
 * de signaallaag — een eigen call eromheen laat de luisteraars achter, en daarmee de
 * foto's, de activiteiten en de voorraad van een werkbon die niet meer bestaat.
 *
 * @param {Object} serviceorder
 * @param {Object} visit_options Inertia-opties, bijvoorbeeld welke props terug moeten komen.
 */
export function deleteServiceOrder(serviceorder, visit_options = {}) {
    if (!confirm(DELETE_CONFIRMATION)) return

    router.delete(`/serviceorders/${serviceorder.id}`, {
        preserveScroll: true,
        ...visit_options,
    })
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
