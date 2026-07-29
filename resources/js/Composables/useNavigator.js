/**
 * The keyboard shortcut for the navigation spotlight.
 *
 * Cmd+K on a Mac, Ctrl+K elsewhere — the combination people already reach for in
 * every other tool with a search box. It goes to this one rather than to the
 * assistant because everybody gets the navigator, while the assistant sits
 * behind a permission. Firefox binds Ctrl+K to its search bar, which is why the
 * handler calls preventDefault before doing anything else.
 *
 * The label and the handler live together on purpose: a button advertising one
 * combination while another one works is worse than no hint at all.
 */

const isMac = () =>
    typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '')

export const navigatorShortcutLabel = () => (isMac() ? '⌘K' : 'Ctrl K')

export const matchesNavigatorShortcut = (event) =>
    event.key?.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey

/** Opens the box from anywhere without every page having to hold a reference to it. */
export const openNavigator = () => window.dispatchEvent(new CustomEvent('navigator:open'))

/** Used by the assistant to get out of the way: two dialogs fight over the focus trap. */
export const closeNavigator = () => window.dispatchEvent(new CustomEvent('navigator:close'))
