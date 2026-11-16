import { AlertCircleIcon, PuzzleIcon } from '@lucide/vue'

/** How each kind of bakje item looks: one and many, its icon and its tile colours. */
const TRAY_KINDS = {
    ticket: {
        one: 'Storing',
        many: 'storingen',
        icon: AlertCircleIcon,
        tile: 'bg-orange-500/15 text-orange-300',
    },
    asset: {
        one: 'Machine',
        many: 'machines',
        icon: PuzzleIcon,
        tile: 'bg-teal-500/15 text-teal-300',
    },
}

export const trayKind = (kind) => TRAY_KINDS[kind]
