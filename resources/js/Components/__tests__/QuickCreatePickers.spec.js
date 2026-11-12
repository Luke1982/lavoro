import { describe, expect, it, vi } from 'vitest'
import { shallowMount } from '@vue/test-utils'
import ProductPicker from '../Products/ProductPicker.vue'
import ProductCreateModal from '../Products/ProductCreateModal.vue'
import CustomerPicker from '../Customers/CustomerPicker.vue'
import CustomerCreateModal from '../Customers/CustomerCreateModal.vue'
import CreatableComboBox from '../UI/CreatableComboBox.vue'

/**
 * The pickers of the machine forms are settings for CreatableComboBox. A typo
 * in a permission would not fail anywhere — the option would just never show —
 * so each one is held to the permission and the form it is meant to have.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => ({ props: { auth: { isAdmin: false, permissions: [] } } }),
}))

const settingsOf = (picker) => shallowMount(picker).findComponent(CreatableComboBox).props()

describe('the pickers that make what they lack', () => {
    it('makes products for whoever may make products', () => {
        expect(settingsOf(ProductPicker)).toMatchObject({
            permission: 'product.create',
            createLabel: 'Nieuw product',
            modal: ProductCreateModal,
        })
    })

    it('makes customers for whoever may make customers', () => {
        expect(settingsOf(CustomerPicker)).toMatchObject({
            permission: 'customer.create',
            createLabel: 'Nieuwe klant',
            modal: CustomerCreateModal,
        })
    })
})
