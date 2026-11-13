import { computed, nextTick, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { buildSerialGroups, groupIntoBundles, rowIsDirty } from '@/Components/ServiceOrders/Tasks/taskSerials.js'

/**
 * The serial fields of one werkbon task: one per machine it delivers, typed or
 * scanned, saved one machine at a time so a mechanic can stop half-way.
 *
 * With checks per machine, a plain product's field belongs to the unit whose
 * checks are tied to that machine; an empty field goes to the next unit without
 * one, and is registered for that unit when saved.
 */
export function useTaskSerials(instance, units) {
    const perUnitPlain = () => !instance.value.product?.bundle && units.value.some(unit => unit.index !== null)

    const groups = ref(build())
    const submitting = ref(false)
    const error = ref('')

    const bundles = computed(() => groupIntoBundles(groups.value))
    const rows = computed(() => groups.value.flatMap(group => group.rows))
    const hasDirty = computed(() => rows.value.some(rowIsDirty))

    const unchangedPlaceholder = row => row.is_placeholder && row.serial_number === row.saved_serial
    const isMissing = row => unchangedPlaceholder(row)
        || (row.requires_serial && !row.asset_id && row.serial_number.trim() === '')

    const ready = computed(() => !rows.value.some(isMissing))
    const missing = computed(() => rows.value.filter(isMissing).length)

    /** Only a change in the machines or in which unit holds which rebuilds the fields; a tick leaves them alone. */
    watch(() => JSON.stringify([instance.value.serial_slots ?? [], units.value.map(unit => [unit.index, unit.assetId])]), () => {
        if (!submitting.value) rebuild()
    })

    function build() {
        const built = buildSerialGroups(instance.value)
        const plain = built.find(group => group.container_index === null)

        if (!plain || !perUnitPlain()) return built

        const unitOf = new Map(units.value.filter(unit => unit.assetId).map(unit => [unit.assetId, unit.index]))
        plain.rows.forEach(row => { row.unit_index = unitOf.get(row.asset_id) ?? null })

        const empty = plain.rows.filter(row => !row.asset_id)
        units.value.filter(unit => unit.index !== null && !unit.assetId)
            .forEach((unit, i) => { if (empty[i]) empty[i].unit_index = unit.index })

        return built
    }

    /** The fields of one unit: its bundle's parts, or its own serial field. */
    function forUnit(unit) {
        if (instance.value.product?.bundle) return groups.value.filter(group => group.container_index === unit.index)

        const plain = groups.value.find(group => group.container_index === null)
        const row = plain?.rows.find(r => r.unit_index === unit.index)

        return row ? [{ ...plain, key: `${plain.key}:${unit.key}`, label: null, expected: 1, rows: [row] }] : []
    }

    function type(row, value) {
        row.serial_number = value
        row.error = ''
    }

    /**
     * Fields that were just sent come back as saved machines and are dropped; anything
     * typed but not sent stays on screen — on the same machine, the same unit, or else
     * the next empty field — so a save or a reload never loses or moves it.
     */
    async function rebuild(sent = []) {
        await nextTick()

        const kept = rows.value.filter(row => !sent.includes(row) && (rowIsDirty(row) || row.error))
        groups.value = build()

        for (const old of kept) {
            const target = old.asset_id
                ? rows.value.find(row => row.asset_id === old.asset_id)
                : rows.value.find(row => !row.asset_id && row.key === old.key && row.serial_number === ''
                    && (old.unit_index === null || row.unit_index === old.unit_index))

            if (target) Object.assign(target, { serial_number: old.serial_number, error: old.error })
        }
    }

    const visit = (method, url, data) => new Promise(resolve => router[method](url, data, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => resolve({ ok: true }),
        onError: errors => resolve({ ok: false, errors }),
    }))

    /** Saves the changed fields (or only `only`); false when the server refused one. */
    async function save(only = null) {
        if (submitting.value) return false

        const dirty = (only ?? rows.value).filter(rowIsDirty)
        error.value = ''
        dirty.forEach(row => { row.error = '' })
        submitting.value = true

        const ok = await send(dirty)

        if (ok && dirty.length) await rebuild(dirty)
        submitting.value = false

        return ok
    }

    async function send(dirty) {
        for (const row of dirty.filter(r => r.asset_id)) {
            const result = await visit('patch', `/serviceordertaskinstances/${instance.value.id}/assets/${row.asset_id}`,
                { serial_number: row.serial_number.trim() })

            if (!result.ok) {
                row.error = result.errors.serial_number ?? 'Opslaan is mislukt.'
                return false
            }
        }

        const created = dirty.filter(r => !r.asset_id)

        if (!created.length) return true

        const result = await visit('post', `/serviceordertaskinstances/${instance.value.id}/assets`, {
            assets: created.map(row => ({
                product_id: row.product_id,
                container_index: row.container_index,
                unit_index: row.unit_index,
                serial_number: row.requires_serial ? row.serial_number.trim() : null,
            })),
        })

        if (!result.ok) {
            created.forEach((row, i) => { row.error = result.errors[`assets.${i}.serial_number`] ?? '' })
            error.value = result.errors.assets ?? ''
        }

        return result.ok
    }

    return { groups, bundles, hasDirty, ready, missing, submitting, error, forUnit, type, save }
}
