import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import axios from 'axios'
import ProductCreateModal from '../ProductCreateModal.vue'

/**
 * The product made on top of the machine form: it starts from what was typed in
 * the picker and from the brands and types there are now, and saves through the
 * same endpoint as the products page. How saving goes is QuickCreateModal's.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => ({ props: { auth: { isAdmin: false, permissions: ['product.create'] } } }),
}))

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

const ModalStub = {
    name: 'ModalDialog',
    props: ['open', 'title', 'maxWidthClass'],
    template: '<div v-if="open"><slot /><slot name="footer" /></div>',
}

const formOptions = {
    brands: [{ id: 1, name: 'Bosch' }],
    productTypes: [{ id: 5, name: 'Boormachine' }],
    productAttributes: [],
}

const option = {
    id: 40,
    name: 'Bosch GSR 18 (Boormachine)',
    bundle: false,
    registable: true,
    typical_certificate_days: null,
    product_type_typical_certificate_days: 180,
}

let wrapper

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    }
})

beforeEach(() => {
    axios.get.mockReset()
    axios.post.mockReset()
    axios.get.mockImplementation((url) =>
        Promise.resolve(url === '/products/create' ? { data: formOptions } : {})
    )
})

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
})

const settle = () => new Promise((resolve) => setTimeout(resolve))

const openModal = async () => {
    wrapper = mount(ProductCreateModal, {
        props: { open: false, typed: 'GSR 18', 'onUpdate:open': (value) => wrapper.setProps({ open: value }) },
        global: { stubs: { ModalDialog: ModalStub }, directives: { tooltip: {} } },
    })
    await wrapper.setProps({ open: true })
    await settle()
}

const save = async () => {
    await wrapper.findAll('button').find(button => button.text() === 'Product aanmaken').trigger('click')
    await settle()
}

describe('ProductCreateModal', () => {
    it('starts from the model that was typed and the brands and types there are now', async () => {
        await openModal()

        expect(axios.get).toHaveBeenCalledWith('/products/create')
        expect(wrapper.findAll('input[type="text"]').map(input => input.element.value)).toContain('GSR 18')
    })

    it('saves through the products endpoint and hands back the option it made', async () => {
        axios.post.mockResolvedValue({ data: option })
        await openModal()

        await save()

        expect(axios.post).toHaveBeenCalledWith('/products', expect.objectContaining({
            model: 'GSR 18',
            attributes: [],
        }))
        expect(wrapper.emitted('created')).toEqual([[option]])
        expect(wrapper.emitted('update:open')).toEqual([[false]])
    })

    it('says so when the brands and types will not load', async () => {
        axios.get.mockRejectedValue(new Error('offline'))
        await openModal()

        expect(wrapper.find('[role="alert"]').text()).toBe('De keuzelijsten voor een product konden niet worden geladen.')
    })
})
