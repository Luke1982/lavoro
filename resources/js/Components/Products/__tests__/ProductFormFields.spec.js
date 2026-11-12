import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import axios from 'axios'
import ProductFormFields from '../ProductFormFields.vue'
import { useProductForm } from '@/Composables/useProductForm'

/**
 * A brand or type that is missing is made from its own picker, by typing it.
 * Only for whoever may make one, and whatever the server refuses is said under
 * the field it is about.
 */
let permissions = []

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => ({ props: { auth: { isAdmin: false, permissions } } }),
}))

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

const bosch = { id: 1, name: 'Bosch' }
const boormachine = { id: 5, name: 'Boormachine' }

let wrapper
let product

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    }
})

beforeEach(() => {
    axios.get.mockResolvedValue({})
    axios.post.mockReset()
})

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    document.body.innerHTML = ''
})

const settle = () => new Promise((resolve) => setTimeout(resolve))

const mountFields = () => {
    product = useProductForm([])
    wrapper = mount(ProductFormFields, {
        props: {
            form: product.form,
            attributeValues: product.attributeValues,
            brands: [bosch],
            productTypes: [boormachine],
        },
        global: { directives: { tooltip: {} } },
    })
}

const pickers = () => wrapper.findAll('input[role="combobox"]')
const typePicker = () => pickers()[0]
const brandPicker = () => pickers()[1]

const type = async (input, text) => {
    input.element.value = text
    await input.trigger('input')
    await settle()
}

const createRow = () => document.querySelector('[data-combobox-create]')

const pickCreateRow = async () => {
    createRow().dispatchEvent(new MouseEvent('mousedown', { bubbles: true, button: 0 }))
    await settle()
    await settle()
}

describe('ProductFormFields', () => {
    it('offers to make a brand that is not in the list to whoever may make brands', async () => {
        permissions = ['brand.create']
        mountFields()

        await type(brandPicker(), 'Makita')

        expect(createRow().textContent).toContain('Nieuw merk ‘Makita’')
    })

    it('does not offer it to whoever may not', async () => {
        permissions = ['product.create']
        mountFields()

        await type(brandPicker(), 'Makita')

        expect(createRow()).toBeNull()
    })

    it('makes the brand and selects it in the form', async () => {
        permissions = ['brand.create']
        axios.post.mockResolvedValue({ data: { id: 9, name: 'Makita' } })
        mountFields()

        await type(brandPicker(), 'Makita')
        await pickCreateRow()

        expect(axios.post).toHaveBeenCalledWith('/brands', { name: 'Makita' })
        expect(product.form.brand_id).toBe(9)
        expect(brandPicker().element.value).toBe('Makita')
    })

    it('says under the brand what the server refused', async () => {
        permissions = ['brand.create']
        axios.post.mockRejectedValue({
            response: { status: 422, data: { errors: { name: ['Deze naam is al in gebruik.'] } } },
        })
        mountFields()

        await type(brandPicker(), 'Makita')
        await pickCreateRow()

        expect(product.form.brand_id).toBeNull()
        expect(wrapper.text()).toContain('Deze naam is al in gebruik.')
    })

    it('makes a product type the same way, for whoever may make product types', async () => {
        permissions = ['producttype.create']
        axios.post.mockResolvedValue({ data: { id: 12, name: 'Schroefmachine' } })
        mountFields()

        await type(typePicker(), 'Schroefmachine')
        await pickCreateRow()

        expect(axios.post).toHaveBeenCalledWith('/producttypes', { name: 'Schroefmachine' })
        expect(product.form.product_type_id).toBe(12)
    })
})
