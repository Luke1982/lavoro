import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import CurrencyInput from '../CurrencyInput.vue'

const currencyInput = (modelValue = null) => mount(CurrencyInput, { props: { modelValue } })

const settle = async (wrapper) => {
    await wrapper.vm.$nextTick()

    return wrapper.find('input')
}

/**
 * vue-currency-input reads modelValue once and never watches it, so a value set
 * from the outside used to sit in the form while the field stayed empty. That is
 * how a contract template's price went missing.
 */
describe('CurrencyInput', () => {
    it('shows the value it was mounted with', async () => {
        const input = await settle(currencyInput(450))

        expect(input.element.value).toBe('450,00')
    })

    it('follows a value put in from the outside', async () => {
        const wrapper = currencyInput(null)
        await settle(wrapper)

        await wrapper.setProps({ modelValue: 450 })

        expect((await settle(wrapper)).element.value).toBe('450,00')
    })

    it('accepts the string a decimal column serves', async () => {
        const wrapper = currencyInput(null)
        await settle(wrapper)

        await wrapper.setProps({ modelValue: '1234.50' })

        expect((await settle(wrapper)).element.value).toBe('1.234,50')
    })

    it('empties when the value is taken away', async () => {
        const wrapper = currencyInput(450)
        await settle(wrapper)

        await wrapper.setProps({ modelValue: null })

        expect((await settle(wrapper)).element.value).toBe('')
    })

    it('reports what is typed into it as a number', async () => {
        const wrapper = currencyInput(null)
        const input = await settle(wrapper)

        input.element.value = '12'
        await input.trigger('input')

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([12])
    })

    it('does not fight the parent echoing back what was typed', async () => {
        const wrapper = currencyInput(null)
        const input = await settle(wrapper)

        input.element.value = '12'
        await input.trigger('input')
        await wrapper.setProps({ modelValue: 12 })
        await settle(wrapper)

        expect(wrapper.emitted('update:modelValue')?.length).toBe(1)
    })
})
