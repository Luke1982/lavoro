/**
 * The keyboard shortcut, in one place.
 *
 * Cmd+/ on a Mac, Ctrl+/ elsewhere. Not Ctrl+K, which now opens the navigation
 * spotlight that everybody has, and not Ctrl+J, which Firefox and Chrome both
 * spend on their downloads panel. Ctrl+/ is unclaimed in every major browser, so
 * the handler is free to take it.
 *
 * The label and the test have to agree, so they live together: a button that
 * advertises one combination while another one works is worse than no hint.
 */

const isMac = () =>
    typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '')

export const assistantShortcutLabel = () => (isMac() ? '⌘/' : 'Ctrl /')

export const matchesAssistantShortcut = (event) =>
    event.key === '/' && (event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey

/** Opens the box from anywhere without every page having to hold a reference to it. */
export const openAssistant = () => window.dispatchEvent(new CustomEvent('assistant:open'))

/** Used by the navigator to get out of the way: two dialogs fight over the focus trap. */
export const closeAssistant = () => window.dispatchEvent(new CustomEvent('assistant:close'))
