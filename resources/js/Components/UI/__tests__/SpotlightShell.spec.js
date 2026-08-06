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

    /**
     * Empty is both the start and the end, so an untouched box hands both arrows
     * over — which is what makes recalling an earlier question feel like a shell.
     */
    it('hands the arrows over when the caret has nowhere to go', async () => {
        const wrapper = shell()

        await wrapper.find('textarea').trigger('keydown', { key: 'ArrowUp' })
        await wrapper.find('textarea').trigger('keydown', { key: 'ArrowDown' })

        expect(wrapper.emitted('up')).toHaveLength(1)
        expect(wrapper.emitted('down')).toHaveLength(1)
    })

    it('leaves the arrows to the caret in the middle of a question', async () => {
        const wrapper = shell({ modelValue: 'regel een\nregel twee' })
        const field = wrapper.find('textarea')

        field.element.selectionStart = 4
        field.element.selectionEnd = 4

        await field.trigger('keydown', { key: 'ArrowUp' })
        await field.trigger('keydown', { key: 'ArrowDown' })

        expect(wrapper.emitted('up')).toBeUndefined()
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

    /**
     * Files dropped or pasted on the panel go to whoever is showing it — but
     * only when that caller says it takes files. The navigator has no use for
     * them, and a drop it does not expect should stay a browser default.
     */
    it('hands dropped files over when the caller takes them', async () => {
        const wrapper = shell({ acceptsFiles: true })
        const file = new File(['x'], 'plaatje.jpg', { type: 'image/jpeg' })

        await wrapper.find('.mx-auto').trigger('drop', { dataTransfer: { files: [file] } })

        expect(wrapper.emitted('files')).toHaveLength(1)
        expect(wrapper.emitted('files')[0][0][0].name).toBe('plaatje.jpg')
    })

    it('ignores drops when the caller never asked for files', async () => {
        const wrapper = shell()
        const file = new File(['x'], 'plaatje.jpg', { type: 'image/jpeg' })

        await wrapper.find('.mx-auto').trigger('drop', { dataTransfer: { files: [file] } })

        expect(wrapper.emitted('files')).toBeUndefined()
    })
})
