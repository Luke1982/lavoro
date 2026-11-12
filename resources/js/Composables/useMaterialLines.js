import { computed, ref, toValue } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { hasAnyPermission, hasPermission } from '@/Utilities/Utilities.js'

/**
 * Who may do what with the materials on a werkbon. Usable on its own by a screen that
 * only needs to know whether to show the materials at all.
 */
export function useMaterialPermissions() {
    return {
        canSee: computed(() => hasAnyPermission([
            'material.read.serviceorder',
            'freeformmaterial.create',
            'freeformmaterial.delete',
            'freeformmaterial.update',
            'freeformmaterial.read',
        ])),
        canSeeFinancials: computed(() => hasPermission('serviceorder.see_financials')),
        canCreate: computed(() => hasPermission('materiable.create.serviceorder')),
        canCreateMaterial: computed(() => hasPermission('material.create')),
        canUpdate: computed(() => hasPermission('materiable.update.serviceorder')),
        canDelete: computed(() => hasPermission('materiable.delete.serviceorder')),
        canCreateFreeform: computed(() => hasPermission('freeformmaterial.create')),
        canUpdateFreeform: computed(() => hasPermission('freeformmaterial.update')),
        canDeleteFreeform: computed(() => hasPermission('freeformmaterial.delete')),
    }
}

/**
 * What can be done to the material lines of a werkbon or task. One place for the full
 * materials box and the monteur's compact list, so both call the same addresses and
 * agree on which lines are foreign or may be changed or removed.
 *
 * A line is either a catalogue material, carrying the pivot it is booked through, or a
 * free line with its own id; every action below works out which by itself.
 *
 * @param {object} options
 * @param {import('vue').MaybeRefOrGetter<number>} options.serviceOrderId
 * @param {import('vue').MaybeRefOrGetter<string|null>} [options.endpointBase] a task instead of the werkbon
 * @param {import('vue').MaybeRefOrGetter<number|null>} [options.taskInstanceId] the task this list belongs to
 */
export function useMaterialLines({ serviceOrderId, endpointBase = null, taskInstanceId = null }) {
    const permissions = useMaterialPermissions()
    const base = computed(() => toValue(endpointBase) ?? '/serviceorders/' + toValue(serviceOrderId))

    const isFreeform = (line) => !line.pivot

    /**
     * A line booked against a different owner is read-only for edits here: its pivot
     * belongs elsewhere, so quantities are corrected on that task's tile, not in this list.
     * It can still be removed from here — the call is routed to its own owner.
     */
    function isForeign(line) {
        return !!line.task_instance_id && line.task_instance_id !== toValue(taskInstanceId)
    }

    function canEditLine(line) {
        return !isForeign(line)
            && (isFreeform(line) ? permissions.canUpdateFreeform.value : permissions.canUpdate.value)
    }

    /**
     * A line from a task the viewer may not see arrives without a label. It still counts toward
     * the total, but removing something you cannot identify is withheld.
     */
    function canRemoveLine(line) {
        return (!isForeign(line) || !!line.task_label)
            && (isFreeform(line) ? permissions.canDeleteFreeform.value : permissions.canDelete.value)
    }

    /** Its own owner's address: the task it was booked on, otherwise this list's owner. */
    function lineUrl(line) {
        const owner = line.task_instance_id ? '/serviceordertaskinstances/' + line.task_instance_id : base.value

        return owner + (isFreeform(line) ? '/freeform-materials/' + line.id : '/materials/' + line.pivot.id)
    }

    const quantityErrors = ref({})

    const quantityError = (line) => quantityErrors.value[lineUrl(line)] ?? ''

    function updateLine(line, changes) {
        const url = lineUrl(line)

        quantityErrors.value[url] = ''
        useForm(changes).put(url, {
            preserveScroll: true,
            onError: (errors) => { quantityErrors.value[url] = errors.quantity ?? '' },
        })
    }

    function removeLine(line) {
        useForm({}).delete(lineUrl(line), { preserveScroll: true })
    }

    return {
        ...permissions,
        base,
        isForeign,
        canEditLine,
        canRemoveLine,
        quantityError,
        updateLine,
        removeLine,
    }
}
