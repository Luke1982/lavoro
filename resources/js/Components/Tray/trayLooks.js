import { AlertCircleIcon, ImageIcon, PuzzleIcon } from '@lucide/vue'

/**
 * How each kind of bakje item looks: its icon and its tile colours. What it is
 * called and where it can go come from the server, with the bakje itself.
 */
const TRAY_LOOKS = {
    ticket: { icon: AlertCircleIcon, tile: 'bg-orange-500/15 text-orange-300' },
    asset: { icon: PuzzleIcon, tile: 'bg-teal-500/15 text-teal-300' },
    image: { icon: ImageIcon, tile: 'bg-sky-500/15 text-sky-300' },
}

export const trayLook = (kind) => TRAY_LOOKS[kind]

/** The count beside the word Bakje. Keyed on the number, so it pops each time it changes. */
export const TRAY_COUNT = 'inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-sidebar-indicator px-1.5 text-xs font-bold text-sidebar-bg animate-[trayBump_450ms_ease-out] motion-reduce:animate-none'
