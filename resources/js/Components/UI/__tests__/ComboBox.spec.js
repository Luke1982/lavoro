import { describe, it, expect, beforeAll, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

import ComboBox from '../ComboBox.vue'

/**
 * What the box says when it has nothing to offer, and what it still finds when
 * what you type is not literally what someone once stored.
 *
 * Before this, a list that filtered down to nothing simply did not render: the
 * box closed without a word, which reads as a field that is broken or a
 * location you are not allowed to pick. A customer reported exactly that.
 */
const rustenburg = { id: 92, name: 'Rustenburg 24  - Wageningen – Wageningen' }
const beethovenlaan = { id: 7, name: 'Beethovenlaan 120 - Doorwerth – Doorwerth' }

let box

beforeAll(() => {
    /** @floating-ui positions the panel with one, and jsdom has none. */
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    }
})

afterEach(() => {
    box?.unmount()
    box = null
})

const combobox = (props = {}) => {
    box = mount(ComboBox, {
        props: { options: [rustenburg, beethovenlaan], ...props },
    })

    return box
}

const settle = () => new Promise((resolve) => setTimeout(resolve))

const type = async (wrapper, text) => {
    const input = wrapper.find('input')
    input.element.value = text
    await input.trigger('input')
    await settle()
}

/** Typing is also what opens the box, so an empty term is how we just open it. */
const openBox = (wrapper) => type(wrapper, '')

const panel = () => document.querySelector('[data-combobox-dropdown]')

describe('ComboBox', () => {
    it('finds a location typed the way you would write it, not the way it was stored', async () => {
        const wrapper = combobox()

        await type(wrapper, 'Rustenburg 24 - Wageningen')

        const offered = [...panel().querySelectorAll('[role="option"]')].map(o => o.textContent.trim())
        expect(offered).toEqual([rustenburg.name])
    })

    it('ignores accents and punctuation on both sides of the match', async () => {
        const wrapper = combobox({ options: [{ id: 1, name: 'Café de Unie, Rotterdam' }] })

        await type(wrapper, 'cafe de unie rotterdam')

        expect(panel().querySelectorAll('[role="option"]')).toHaveLength(1)
    })

    it('searches what the option brought along, postcode spacing and all', async () => {
        const wrapper = combobox({
            options: [{ id: 1, name: 'Vestiging Noord – Wageningen', search: 'VN-01 Rustenburg 24 6702 AE' }],
        })

        await type(wrapper, '6702ae')

        expect(panel().querySelectorAll('[role="option"]')).toHaveLength(1)
    })

    it('says that nothing matches instead of closing without a word', async () => {
        const wrapper = combobox()

        await type(wrapper, 'Rustenberg')

        expect(panel().textContent).toContain('Geen resultaten voor ‘Rustenberg’')
    })

    it('shows the reason the list is empty when it is handed one', async () => {
        const wrapper = combobox({
            options: [],
            emptyMessage: 'Deze klant heeft nog geen locaties.',
        })

        await openBox(wrapper)

        expect(panel().textContent).toContain('Deze klant heeft nog geen locaties.')
    })

    it('stays shut when it has nothing to offer and no reason to give', async () => {
        const wrapper = combobox({ options: [] })

        await openBox(wrapper)

        expect(panel()).toBeNull()
    })

    it('holds its tongue while the parent is still searching', async () => {
        const wrapper = combobox({
            options: [],
            hasExternalSearching: true,
            searching: true,
        })

        await type(wrapper, 'Rust')
        expect(panel()).toBeNull()

        await wrapper.setProps({ searching: false })
        await settle()

        expect(panel().textContent).toContain('Geen resultaten voor ‘Rust’')
    })
})

/**
 * A picker that may make what it does not have. Whoever cannot find a brand
 * while adding a product types it, and the last row offers to make exactly
 * that — the parent makes it, because only the parent knows the endpoint and
 * whether this person is allowed to.
 */
describe('ComboBox offering to make what is missing', () => {
    const bosch = { id: 1, name: 'Bosch' }

    const createRow = () => document.querySelector('[data-combobox-create]')

    /** Headless UI picks an option on mousedown, not on click. */
    const pick = async (element) => {
        element.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, button: 0 }))
        await settle()
    }

    it('offers to make what was typed when the list does not have it', async () => {
        const wrapper = combobox({ options: [bosch], createLabel: 'Nieuw merk' })

        await type(wrapper, 'Makita')

        expect(createRow().textContent).toContain('Nieuw merk ‘Makita’')
    })

    it('hands what was typed to the parent and selects nothing itself', async () => {
        const wrapper = combobox({ options: [bosch], createLabel: 'Nieuw merk' })

        await type(wrapper, '  Makita ')
        await pick(createRow())

        expect(wrapper.emitted('create')).toEqual([['Makita']])
        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    })

    it('keeps the choice already made while the new one is being made', async () => {
        const wrapper = combobox({ options: [bosch], modelValue: 1, createLabel: 'Nieuw merk' })

        await type(wrapper, 'Makita')
        await pick(createRow())

        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
        expect(wrapper.find('input').element.value).toBe('Bosch')
    })

    it('keeps the choice even when a search has replaced the list it came from', async () => {
        const wrapper = combobox({ options: [bosch], modelValue: 1, createLabel: 'Nieuw merk' })

        await wrapper.setProps({ options: [{ id: 2, name: 'Makita' }] })
        await type(wrapper, 'Metabo')
        await pick(createRow())

        expect(wrapper.find('input').element.value).toBe('Bosch')
    })

    it('does not offer a second of what is already in the list', async () => {
        const wrapper = combobox({ options: [bosch], createLabel: 'Nieuw merk' })

        await type(wrapper, 'bosch')

        expect(createRow()).toBeNull()
    })

    it('waits for a name before offering to make anything', async () => {
        const wrapper = combobox({ options: [bosch], createLabel: 'Nieuw merk' })

        await openBox(wrapper)

        expect(createRow()).toBeNull()
    })

    it('offers to make it once the server found nothing, and not while it is still looking', async () => {
        const wrapper = combobox({ options: [], hasExternalSearching: true, createLabel: 'Nieuw product' })

        await wrapper.setProps({ searching: true })
        await type(wrapper, 'GSR 18')
        expect(createRow()).toBeNull()

        await wrapper.setProps({ searching: false })
        await settle()

        expect(createRow().textContent).toContain('Nieuw product ‘GSR 18’')
    })

    it('never offers to make anything without being asked to', async () => {
        const wrapper = combobox({ options: [bosch] })

        await type(wrapper, 'Makita')

        expect(createRow()).toBeNull()
    })
})
