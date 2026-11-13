import { computed } from 'vue'

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`
const inTasks = n => (n === 0 ? 'nog in geen enkele taak' : `in ${plural(n, 'taak', 'taken')}`)

/**
 * The checklist editor's picture of the library: every card by its key
 * ("check:4", "checklist:2", "task:7"), every line between them, how many tasks
 * each check or checklist ends up on, and what an opened card holds.
 */
export function useChecklistGraph(props) {
    const cards = computed(() => new Map([
        ...props.checks.map(check => ['check:' + check.id, check]),
        ...props.checklists.map(list => ['checklist:' + list.id, list]),
        ...props.tasks.map(task => ['task:' + task.id, task]),
    ]))

    const titleOf = key => cards.value.get(key)?.title ?? ''

    const line = (link, kind, from, to) => ({
        key: (kind === 'checklist' ? 'll:' : 'cl:') + link.id,
        id: link.id,
        kind,
        from,
        to,
        required: link.is_required,
    })

    /** Every line in the editor, from check to checklist or task, and from checklist to task. */
    const links = computed(() => [
        ...props.checklists.flatMap(list => list.links
            .map(link => line(link, 'check', 'check:' + link.check_id, 'checklist:' + list.id))),
        ...props.tasks.flatMap(task => [
            ...task.check_links.map(link => line(link, 'check', 'check:' + link.check_id, 'task:' + task.id)),
            ...task.checklist_links
                .map(link => line(link, 'checklist', 'checklist:' + link.checklist_id, 'task:' + task.id)),
        ]),
    ])

    /** The tasks each check or checklist ends up on; a check counts its checklists' tasks too. */
    const tasksOf = computed(() => {
        const tasks = new Map()
        const add = (key, task) => tasks.set(key, (tasks.get(key) ?? new Set()).add(task))

        links.value.filter(link => link.to.startsWith('task:')).forEach(link => add(link.from, link.to))
        links.value.filter(link => link.to.startsWith('checklist:')).forEach(link => {
            tasks.get(link.to)?.forEach(task => add(link.from, task))
        })

        return tasks
    })

    const usage = key => tasksOf.value.get(key)?.size ?? 0

    /** "zit in 3 taken", for the question before deleting a check or checklist. */
    const usageSentence = key => 'zit ' + inTasks(usage(key))

    function meta(key) {
        const item = cards.value.get(key)

        if (key.startsWith('check:')) return inTasks(usage(key))
        if (key.startsWith('checklist:')) return plural(item.links.length, 'check', 'checks') + ' · ' + inTasks(usage(key))

        const parts = [
            item.checklist_links.length ? plural(item.checklist_links.length, 'checklist', 'checklists') : null,
            item.check_links.length ? plural(item.check_links.length, 'losse check', 'losse checks') : null,
        ].filter(Boolean)

        return parts.length ? parts.join(' · ') : 'nog geen checks'
    }

    /** The cards connected to this one, itself included; for a task also the checks inside its checklists. */
    function connectedTo(key) {
        const keys = new Set([key])

        links.value.forEach(link => {
            if (link.from === key) keys.add(link.to)
            if (link.to === key) keys.add(link.from)
        })

        if (key.startsWith('task:')) {
            links.value.filter(link => keys.has(link.to) && link.to.startsWith('checklist:'))
                .forEach(link => keys.add(link.from))
        }

        return keys
    }

    const row = (link, key) => ({ linkId: link.id, title: titleOf(key), required: link.is_required })

    /** What an opened card holds, in the order the mechanic sees it. */
    const contents = {
        checklist: list => list.links.map(link => row(link, 'check:' + link.check_id)),
        taskChecklists: task => task.checklist_links.map(link => row(link, 'checklist:' + link.checklist_id)),
        taskChecks: task => task.check_links.map(link => row(link, 'check:' + link.check_id)),
    }

    return { links, titleOf, usageSentence, meta, connectedTo, contents }
}
