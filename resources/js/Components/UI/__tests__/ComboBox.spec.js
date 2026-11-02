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
