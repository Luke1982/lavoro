/**
 * The machines a werkbon task delivers, as the server lays them out in `serial_slots`:
 * counted for the task row, summed per bundle part, and turned into the rows the task
 * box fills in.
 */

/** How many machines the task delivers, and how many have their real serial; a placeholder does not count. */
export function serialCounts(instance) {
    const slots = instance.serial_slots ?? []

    return {
        expected: slots.reduce((total, group) => total + group.expected, 0),
        filled: slots.reduce((total, group) => {
            const real = group.assets.filter(asset => !asset.is_placeholder).length

            return total + Math.min(group.expected, real)
        }, 0),
    }
}

/**
 * What a bundeltaak is made of, summed back up across the bundles it sells: the slots hold
 * the parts once per bundle machine. A taak for a plain product has no parts of its own and
 * reads as nothing here.
 */
export function bundleParts(instance) {
    const totals = new Map()

    for (const group of instance.serial_slots ?? []) {
        if (group.container_index === null) continue

        const seen = totals.get(group.product_id)

        totals.set(group.product_id, {
            key: group.product_id,
            label: group.label,
            requires_serial: group.requires_serial,
            count: (seen?.count ?? 0) + group.expected,
        })
    }

    return [...totals.values()]
}

/**
 * The server decides which machines a task expects; a row is only ever a saved asset
 * or an empty slot waiting for one.
 */
export function buildSerialGroups(instance) {
    return (instance.serial_slots ?? []).map(group => {
        const rowFor = (asset) => ({
            asset_id: asset?.id ?? null,
            key: group.key,
            product_id: group.product_id,
            container_index: group.container_index,
            unit_index: null,
            requires_serial: group.requires_serial,
            serial_number: asset?.serial_number ?? '',
            saved_serial: asset?.serial_number ?? '',
            is_placeholder: asset?.is_placeholder ?? false,
            error: '',
        })

        const saved = group.assets.map(rowFor)
        const empty = Array.from({ length: Math.max(0, group.expected - saved.length) }, () => rowFor(null))

        return {
            key: group.key,
            product_id: group.product_id,
            container_index: group.container_index,
            container_label: group.container_label,
            requires_serial: group.requires_serial,
            label: group.label,
            expected: group.expected,
            rows: [...saved, ...empty],
        }
    })
}

/**
 * A bundle taak delivers the bundle itself with its parts underneath, so its slots are
 * shown per bundle machine rather than in one long list. A plain product has no bundle to
 * group under and lands in a single unnamed block.
 */
export function groupIntoBundles(groups) {
    const bundles = new Map()

    groups.forEach(group => {
        const key = group.container_index ?? 'none'

        if (!bundles.has(key)) {
            bundles.set(key, { key, index: group.container_index, label: group.container_label, groups: [] })
        }

        bundles.get(key).groups.push(group)
    })

    return [...bundles.values()]
}

/**
 * A row is pending as long as it has not become a machine yet. A part that carries no
 * serienummer has nothing to type, so it counts the moment its slot is still empty.
 */
export function rowIsDirty(row) {
    if (!row.requires_serial) return !row.asset_id

    return row.serial_number.trim() !== '' && row.serial_number.trim() !== row.saved_serial
}
