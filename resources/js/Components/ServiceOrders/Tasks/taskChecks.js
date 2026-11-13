/**
 * The checks of a werkbon task as the screens count and group them. Pure, so the
 * folded task row and the open task box always agree.
 */

export function checkCounts(checks = []) {
    return {
        done: checks.filter(check => check.is_done).length,
        total: checks.length,
        openRequired: checks.filter(check => check.is_required && !check.is_done).length,
    }
}

/**
 * One group per machine ("Daikin 3MXM52A · 2/3"), or a single unnamed group when
 * the checks are done once for the whole task. Machines are numbered by their
 * place, so a removed machine never leaves a gap in the numbers.
 */
export function checkUnits(checks = [], productLabel = '') {
    const groups = new Map()

    for (const check of checks) {
        const key = check.unit_index ?? 'all'

        if (!groups.has(key)) groups.set(key, [])
        groups.get(key).push(check)
    }

    const machines = [...groups.keys()].filter(key => key !== 'all').length

    return [...groups.entries()].map(([key, list], position) => ({
        key: String(key),
        index: key === 'all' ? null : key,
        position: position + 1,
        label: key === 'all' ? null : [productLabel, `${position + 1}/${machines}`].filter(Boolean).join(' · '),
        assetId: list.find(check => check.asset_id)?.asset_id ?? null,
        checks: list,
    }))
}

/**
 * The extras a task carries, in the shape the extras picker edits: single library
 * checks, whole checklists, and titles typed for this task only.
 */
export function extrasOf(checks = []) {
    const extras = checks.filter(check => check.is_extra)
    const unique = list => [...new Set(list)]

    return {
        check_ids: unique(extras.filter(c => c.service_order_task_check_id && !c.service_order_task_checklist_id)
            .map(c => c.service_order_task_check_id)),
        checklist_ids: unique(extras.filter(c => c.service_order_task_checklist_id)
            .map(c => c.service_order_task_checklist_id)),
        titles: unique(extras.filter(c => !c.service_order_task_check_id && !c.service_order_task_checklist_id)
            .map(c => c.title)),
    }
}
