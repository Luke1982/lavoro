import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SpotlightShell from '../SpotlightShell.vue'

/**
 * Two spotlights share this chrome and they want opposite things from the same
 * keys. The navigator moves a selection with the arrows; the assistant lets you
 * write several lines and needs those keys for the caret. Getting that backwards
 * is not a crash, it is a box that quietly stops behaving like a text field.
 */
const shell = (props = {}) =>
    mount(SpotlightShell, {
        props: { open: true, ...props },
        global: {
            stubs: {
                // Headless UI keeps the panel out of the document until its
                // transition has run, and there is no transition in a test.
                TransitionRoot: { template: '<div><slot /></div>' },
                TransitionChild: { template: '<div><slot /></div>' },
                Dialog: { template: '<div><slot /></div>' },
                DialogPanel: { template: '<div><slot /></div>' },
            },
        },
    })

describe('SpotlightShell', () => {
    it('sends the question on Enter', async () => {
        const wrapper = shell()

        await wrapper.find('textarea').trigger('keydown', { key: 'Enter' })

        expect(wrapper.emitted('enter')).toHaveLength(1)
    })

    it('leaves Shift+Enter alone so a question can have two lines', async () => {
        const wrapper = shell()

        await wrapper.find('textarea').trigger('keydown', { key: 'Enter', shiftKey: true })

        expect(wrapper.emitted('enter')).toBeUndefined()
    })

    it('wraps rather than scrolling sideways', () => {
        const field = shell().find('textarea')

        expect(field.classes()).toContain('resize-none')
        expect(field.attributes('class')).not.toContain('whitespace-nowrap')
        expect(field.element.tagName).toBe('TEXTAREA')
    })

    it('keeps the arrows for the caret unless the caller wants them', async () => {
        const wrapper = shell()

        await wrapper.find('textarea').trigger('keydown', { key: 'ArrowDown' })

        expect(wrapper.emitted('down')).toBeUndefined()
    })

    it('hands the arrows over when the caller navigates a list with them', async () => {
        const wrapper = shell({ interceptArrows: true })

        await wrapper.find('textarea').trigger('keydown', { key: 'ArrowDown' })
        await wrapper.find('textarea').trigger('keydown', { key: 'ArrowUp' })

        expect(wrapper.emitted('down')).toHaveLength(1)
        expect(wrapper.emitted('up')).toHaveLength(1)
    })

    it('closes on escape', async () => {
        const wrapper = shell()

        await wrapper.find('textarea').trigger('keydown', { key: 'Escape' })

        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    it('reports what was typed', async () => {
        const wrapper = shell()

        await wrapper.find('textarea').setValue('Wie kan er dinsdag?')

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['Wie kan er dinsdag?'])
    })
})
