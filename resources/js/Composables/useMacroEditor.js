import { inject, provide, reactive, ref } from 'vue'
import { newStep } from '@/Components/Macros/macroBlocks.js'

const KEY = Symbol('macroEditor')

/**
 * The macro being built: a list of steps where a question holds a list per answer.
 * Lanes are addressed by the list they are, so a block can be put in, moved or
 * taken out anywhere in the tree from any lane.
 */
export function provideMacroEditor(steps) {
    const root = reactive(steps)
    const selectedId = ref(null)
    const errors = ref({})

    function locate(id, lane = root) {
        for (const [index, step] of lane.entries()) {
            if (step.id === id) return { lane, index, step }

            for (const branch of ['yes', 'no']) {
                const found = step.type === 'question' ? locate(id, step[branch]) : null
                if (found) return found
            }
        }

        return null
    }

    /** Whether a lane sits inside this step, so the step cannot be moved into it. */
    const holdsLane = (step, lane) => step.type === 'question'
        && ['yes', 'no'].some(branch => step[branch] === lane || step[branch].some(inner => holdsLane(inner, lane)))

    const editor = {
        root,
        selectedId,
        /** Messages from the last save, by block id. */
        errors,
        find: id => locate(id)?.step ?? null,
        select: id => { selectedId.value = selectedId.value === id ? null : id },
        insert(lane, index, type) {
            const step = newStep(type)
            lane.splice(index, 0, step)
            selectedId.value = step.id
        },
        move(id, lane, index) {
            const from = locate(id)

            if (!from || holdsLane(from.step, lane)) return

            const sameLaneLater = from.lane === lane && from.index < index
            from.lane.splice(from.index, 1)
            lane.splice(sameLaneLater ? index - 1 : index, 0, from.step)
        },
        remove(id) {
            const found = locate(id)

            if (found) found.lane.splice(found.index, 1)
            if (selectedId.value === id) selectedId.value = null

            errors.value = Object.fromEntries(Object.entries(errors.value).filter(([stepId]) => locate(stepId)))
        },
    }

    provide(KEY, editor)

    return editor
}

export const useMacroEditor = () => inject(KEY)
