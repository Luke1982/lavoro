import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import CreatableComboBox from '../CreatableComboBox.vue'

/**
 * The picker that can make what it does not have. Whoever holds the permission
 * gets the form with what was typed; the record it makes goes back to the form
 * that owns the list, and is selected.
 */
let permissions = []

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => ({ props: { auth: { isAdmin: false, permissions } } }),
}))

const RecordModal = {
    name: 'RecordModal',
    props: ['open', 'typed'],
    emits: ['update:open', 'created'],
    template: '<div data-modal :data-open="open" :data-typed="typed" />',
}

const jansen = { id: 3, name: 'Jansen BV' }
const made = { id: 40, name: 'De Vries' }

let wrapper

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    }
})

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    document.body.innerHTML = ''
})

const settle = () => new Promise((resolve) => setTimeout(resolve))

const mountPicker = () => {
    wrapper = mount(CreatableComboBox, {
        props: {
            options: [jansen],
            modelValue: null,
            createLabel: 'Nieuwe klant',
            permission: 'customer.create',
            modal: RecordModal,
        },
    })
}

const type = async (text) => {
    const input = wrapper.find('input')
    input.element.value = text
    await input.trigger('input')
    await settle()
}

const createRow = () => document.querySelector('[data-combobox-create]')

describe('CreatableComboBox', () => {
    it('offers nothing to make to whoever lacks the permission', async () => {
        permissions = ['asset.create']
        mountPicker()

        await type('De Vries')

        expect(createRow()).toBeNull()
        expect(wrapper.find('[data-modal]').exists()).toBe(false)
    })

    it('opens the form with what was typed', async () => {
        permissions = ['customer.create']
        mountPicker()

        await type('De Vries')
        expect(createRow().textContent).toContain('Nieuwe klant ‘De Vries’')
        createRow().dispatchEvent(new MouseEvent('mousedown', { bubbles: true, button: 0 }))
        await settle()

        const modal = wrapper.find('[data-modal]')
        expect(modal.attributes('data-open')).toBe('true')
        expect(modal.attributes('data-typed')).toBe('De Vries')
    })

    it('hands the new record to the form and selects it', async () => {
        permissions = ['customer.create']
        mountPicker()

        wrapper.findComponent(RecordModal).vm.$emit('created', made)
        await settle()

        expect(wrapper.emitted('created')).toEqual([[made]])
        expect(wrapper.emitted('update:modelValue')).toEqual([[40]])
    })

    it('passes what a ComboBox takes on to the ComboBox', async () => {
        permissions = []
        mountPicker()
        await wrapper.setProps({ placeholder: 'Selecteer klant', hasError: true, errorMessage: 'Kies een klant.' })

        expect(wrapper.find('input').attributes('placeholder')).toBe('Selecteer klant')
        expect(wrapper.text()).toContain('Kies een klant.')
    })
})
