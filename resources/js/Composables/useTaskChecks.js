import { computed, reactive } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import { checkCounts, checkUnits } from '@/Components/ServiceOrders/Tasks/taskChecks.js'
import { requestFailureMessage } from '@/Utilities/requestFailure.js'

let csrf = null

/**
 * Ticking the checks of one werkbon task off, one at a time, without reloading
 * the werkbon.
 *
 * A tick shows at once and is saved in the background. Each check carries its own
 * request: a failure puts back only that check, whatever else was tapped meanwhile.
 * The check objects of the page props are updated in place, so every place that
 * counts them — the folded task row, the open task box — agrees.
 */
export function useTaskChecks(instance) {
    const page = usePage()
    const pending = reactive(new Set())
    const reverts = reactive({})

    const units = computed(() => {
        const product = instance.value.product
        const label = product ? [product.brand?.name, product.model].filter(Boolean).join(' ') : ''

        return checkUnits(instance.value.checks, label)
    })

    const perUnit = computed(() => units.value.some(unit => unit.index !== null))
    const openRequired = computed(() => checkCounts(instance.value.checks).openRequired)

    async function tick(check, value) {
        if (pending.has(check.id)) return

        const before = { is_done: check.is_done, checked_at: check.checked_at, checked_by: check.checked_by }
        check.is_done = value
        pending.add(check.id)

        try {
            csrf ??= axios.get('/sanctum/csrf-cookie').catch(error => {
                csrf = null
                throw error
            })
            await csrf

            const { data } = await axios.patch(`/api/serviceordertaskinstancechecks/${check.id}`, { is_done: value })
            Object.assign(check, { is_done: data.is_done, checked_at: data.checked_at, checked_by: data.checked_by })
        } catch (error) {
            Object.assign(check, before)
            reverts[check.id] = (reverts[check.id] ?? 0) + 1
            page.props.flash.success = null
            page.props.flash.error = requestFailureMessage(error, 'Opslaan is mislukt')
        } finally {
            pending.delete(check.id)
        }
    }

    return { units, perUnit, openRequired, pending, reverts, tick }
}
