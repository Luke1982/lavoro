import { inject, provide } from 'vue'

const IN_GROUP = Symbol('buttonGroup')

const SHAPE = 'inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium cursor-pointer'
const OWN_PILL = 'rounded-full bg-white ring-1 ring-gray-200 dark:bg-slate-800 dark:ring-slate-600'

/** Marks the buttons inside as one ButtonGroup, which draws the pill around them all. */
export const provideButtonGroup = () => provide(IN_GROUP, true)

/**
 * The classes of a pill button: a pill of its own when it stands alone, only its
 * shape inside a ButtonGroup. The button finds that out itself, so no page has to
 * tell it.
 */
export const usePillButton = () => (inject(IN_GROUP, false) ? SHAPE : `${SHAPE} ${OWN_PILL}`)
