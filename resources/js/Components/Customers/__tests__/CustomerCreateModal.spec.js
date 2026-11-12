import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import axios from 'axios'
import CustomerCreateModal from '../CustomerCreateModal.vue'

/**
 * The customer made on top of the machine form starts from the name that was
 * typed in the picker and saves through the same endpoint as the customers page.
 */
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

const ModalStub = {
    name: 'ModalDialog',
    props: ['open', 'title', 'maxWidthClass'],
    template: '<div v-if="open"><slot /><slot name="footer" /></div>',
}

const option = { id: 12, name: 'De Vries – Gouda', plain_name: 'De Vries' }

let wrapper

beforeEach(() => {
    axios.get.mockResolvedValue({})
    axios.post.mockReset()
})

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
})

const settle = () => new Promise((resolve) => setTimeout(resolve))

const openModal = async (typed) => {
    wrapper = mount(CustomerCreateModal, {
        props: { open: false, typed, 'onUpdate:open': (value) => wrapper.setProps({ open: value }) },
        global: { stubs: { ModalDialog: ModalStub } },
    })
    await wrapper.setProps({ open: true })
    await settle()
}

const nameInput = () => wrapper.find('input[placeholder="Naam klant"]')

describe('CustomerCreateModal', () => {
    it('starts from the name that was typed', async () => {
        await openModal('De Vries')

        expect(nameInput().element.value).toBe('De Vries')
    })

    it('saves through the customers endpoint and hands back the option it made', async () => {
        axios.post.mockResolvedValue({ data: option })
        await openModal('De Vries')

        await wrapper.findAll('button').find(button => button.text() === 'Klant aanmaken').trigger('click')
        await settle()

        expect(axios.post).toHaveBeenCalledWith('/customers', expect.objectContaining({ name: 'De Vries' }))
        expect(wrapper.emitted('created')).toEqual([[option]])
    })

    it('starts empty again the next time, not with the last customer', async () => {
        await openModal('De Vries')
        await nameInput().setValue('De Vries en Zonen')
        await wrapper.setProps({ open: false, typed: 'Pietersen' })
        await wrapper.setProps({ open: true })
        await settle()

        expect(nameInput().element.value).toBe('Pietersen')
    })
})
