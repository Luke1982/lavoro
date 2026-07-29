/**
 * The keyboard shortcut, in one place.
 *
 * Cmd+K on a Mac, Ctrl+K elsewhere — the combination people already reach for in
 * every other tool with a search box, which is worth more than being original.
 * The label and the test have to agree, so they live together: a button that
 * advertises one combination while another one works is worse than no hint.
 */

const isMac = () =>
    typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '')

export const assistantShortcutLabel = () => (isMac() ? '⌘K' : 'Ctrl K')

export const matchesAssistantShortcut = (event) =>
    event.key?.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey

/** Opens the box from anywhere without every page having to hold a reference to it. */
export const openAssistant = () => window.dispatchEvent(new CustomEvent('assistant:open'))
