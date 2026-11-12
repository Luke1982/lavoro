import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { useForm } from '@inertiajs/vue3'
import axios from 'axios'
import QuickCreateModal from '../QuickCreateModal.vue'

/**
 * Whatever is made on top of another form is saved the same way: through the
 * record's own endpoint, with each refusal under its field, and handed back.
 */
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

const ModalStub = {
    name: 'ModalDialog',
    props: ['open', 'title', 'maxWidthClass'],
    template: '<div v-if="open"><slot /><slot name="footer" /></div>',
}

let wrapper
let form

beforeEach(() => {
    axios.get.mockReset()
    axios.post.mockReset()
    axios.get.mockResolvedValue({})
})

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
})

const settle = () => new Promise((resolve) => setTimeout(resolve))

const openModal = async (props = {}) => {
    form = useForm({ name: 'De Vries' })
    wrapper = mount(QuickCreateModal, {
        props: {
            open: true,
            title: 'Nieuwe klant',
            submitLabel: 'Klant aanmaken',
            url: '/customers',
            form,
            'onUpdate:open': (value) => wrapper.setProps({ open: value }),
            ...props,
        },
        slots: { default: '<p>velden</p>' },
        global: { stubs: { ModalDialog: ModalStub } },
    })
    await settle()
}

const saveButton = () => wrapper.findAll('button').find(button => button.text() === 'Klant aanmaken')

const save = async () => {
    await saveButton().trigger('click')
    await settle()
}

describe('QuickCreateModal', () => {
    it('saves the form through the endpoint, hands back what was made and closes', async () => {
        axios.post.mockResolvedValue({ data: { id: 7, name: 'De Vries' } })
        await openModal()

        await save()

        expect(axios.post).toHaveBeenCalledWith('/customers', { name: 'De Vries' })
        expect(wrapper.emitted('created')).toEqual([[{ id: 7, name: 'De Vries' }]])
        expect(wrapper.props('open')).toBe(false)
    })

    it('sends the payload instead when the record needs more than the form', async () => {
        axios.post.mockResolvedValue({ data: { id: 7 } })
        await openModal({ payload: () => ({ name: 'De Vries', extra: true }) })

        await save()

        expect(axios.post).toHaveBeenCalledWith('/customers', { name: 'De Vries', extra: true })
    })

    it('stays open and puts each refusal on its field', async () => {
        axios.post.mockRejectedValue({
            response: { status: 422, data: { errors: { name: ['Naam is verplicht.'] } } },
        })
        await openModal()

        await save()

        expect(form.errors.name).toBe('Naam is verplicht.')
        expect(wrapper.emitted('created')).toBeUndefined()
        expect(wrapper.props('open')).toBe(true)
    })

    it('says so when it may not make the record at all', async () => {
        axios.post.mockRejectedValue({ response: { status: 403, data: {} } })
        await openModal()

        await save()

        expect(wrapper.find('[role="alert"]').text()).toBe('Je hebt geen rechten om dit aan te maken.')
    })

    it('holds the fields back, and saving, while what they need is still loading', async () => {
        await openModal({ loading: true })

        expect(wrapper.text()).not.toContain('velden')
        expect(saveButton().attributes('disabled')).toBeDefined()
    })

    it('shows a problem the caller ran into before saving', async () => {
        await openModal({ error: 'De keuzelijsten konden niet worden geladen.' })

        expect(wrapper.find('[role="alert"]').text()).toBe('De keuzelijsten konden niet worden geladen.')
    })
})
