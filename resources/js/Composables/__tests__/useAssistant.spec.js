import { describe, it, expect, afterEach, vi } from 'vitest'
import { assistantShortcutLabel, matchesAssistantShortcut } from '../useAssistant.js'

/**
 * A button that advertises one combination while another one works is worse than
 * no hint at all, so the label and the matcher are checked against each other
 * rather than each against itself.
 */
const press = (key, modifiers = {}) => ({
    key,
    metaKey: false,
    ctrlKey: false,
    altKey: false,
    shiftKey: false,
    ...modifiers,
})

const onPlatform = (platform) => {
    vi.stubGlobal('navigator', { platform, userAgent: platform })
}

afterEach(() => {
    vi.unstubAllGlobals()
})

describe('the assistant shortcut', () => {
    it('opens on Ctrl and slash', () => {
        expect(matchesAssistantShortcut(press('/', { ctrlKey: true }))).toBe(true)
    })

    it('opens on Cmd and slash', () => {
        expect(matchesAssistantShortcut(press('/', { metaKey: true }))).toBe(true)
    })

    it('ignores a slash somebody is only typing', () => {
        expect(matchesAssistantShortcut(press('/'))).toBe(false)
    })

    /**
     * Ctrl+K belongs to the navigator now. Answering to both would open two
     * dialogs onto the same focus trap.
     */
    it('leaves Ctrl+K to the navigator', () => {
        expect(matchesAssistantShortcut(press('k', { ctrlKey: true }))).toBe(false)
    })

    it('does not answer to extra modifiers', () => {
        expect(matchesAssistantShortcut(press('/', { ctrlKey: true, shiftKey: true }))).toBe(false)
        expect(matchesAssistantShortcut(press('/', { ctrlKey: true, altKey: true }))).toBe(false)
    })

    it('shows the combination it actually answers to', () => {
        onPlatform('MacIntel')
        const mac = assistantShortcutLabel()

        expect(mac).toContain('⌘')
        expect(mac).toContain('/')
        expect(matchesAssistantShortcut(press('/', { metaKey: true }))).toBe(true)

        onPlatform('Linux x86_64')
        const other = assistantShortcutLabel()

        expect(other).toContain('Ctrl')
        expect(other).toContain('/')
        expect(matchesAssistantShortcut(press('/', { ctrlKey: true }))).toBe(true)
    })

    it('never advertises a key it ignores', () => {
        onPlatform('Linux x86_64')

        expect(assistantShortcutLabel()).not.toContain('K')
    })
})
