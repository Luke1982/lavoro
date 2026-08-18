import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import dayjs from '@/Utilities/dayjs'
import MaintenanceContractCreateDrawer from '../MaintenanceContractCreateDrawer.vue'

const post = vi.fn()

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue')

    return {
        useForm: (data) => {
            const initial = { ...data }

            return reactive({
                ...data,
                errors: {},
                processing: false,
                post,
                reset(...fields) {
                    const restore = fields.length ? fields : Object.keys(initial)
                    restore.forEach((name) => { this[name] = initial[name] })
                },
                clearErrors: () => {},
            })
        },
    }
})

const jaarcontract = {
    id: 1,
    name: 'Standaard jaarcontract',
    title: 'Onderhoud {jaar} — {klant}',
    duration_months: 12,
    price: '450.00',
    price_interval: 'Jaarlijks',
    price_interval_days: null,
    manage_frequency_per_asset: false,
    frequency: 'Halfjaarlijks',
    frequency_days: null,
    auto_generate: true,
    auto_generate_interval: 'Jaarlijks',
    auto_generate_interval_days: null,
}

const kaalSjabloon = {
    id: 2,
    name: 'Doorlopend, per machine',
    title: null,
    duration_months: null,
    price: null,
    price_interval: null,
    price_interval_days: null,
    manage_frequency_per_asset: true,
    frequency: null,
    frequency_days: null,
    auto_generate: false,
    auto_generate_interval: null,
    auto_generate_interval_days: null,
}

const customers = [
    { id: 7, name: 'Jansen BV – Utrecht', plain_name: 'Jansen BV' },
    { id: 8, name: 'De Vries – Gouda', plain_name: 'De Vries' },
]

const DrawerStub = {
    name: 'DrawerComponent',
    props: ['modelValue', 'title', 'subtitle'],
    template: '<div><slot /><slot name="footer" /></div>',
}

const ComboBoxStub = {
    name: 'ComboBox',
    props: ['options', 'modelValue', 'placeholder', 'hasExternalSearching'],
    emits: ['update:modelValue', 'change'],
    template: '<div class="combobox" :data-placeholder="placeholder"></div>',
}

const drawer = (props = {}) => mount(MaintenanceContractCreateDrawer, {
    props: {
        modelValue: true,
        customers,
        templates: [jaarcontract, kaalSjabloon],
        contractIntervalOptions: [
            { id: 'maandelijks', name: 'Maandelijks' },
            { id: 'halfjaarlijks', name: 'Halfjaarlijks' },
            { id: 'jaarlijks', name: 'Jaarlijks' },
            { id: 'aangepast', name: 'Aangepast (dagen)' },
        ],
        ...props,
    },
    global: { stubs: { DrawerComponent: DrawerStub, ComboBox: ComboBoxStub } },
})

const combo = (wrapper, placeholder) =>
    wrapper.findAllComponents(ComboBoxStub).find((c) => c.props('placeholder') === placeholder)

const row = (wrapper, label) =>
    wrapper.findAll('div.grid').find((r) => r.find('label').exists() && r.find('label').text() === label)

const field = (wrapper, label) => {
    const found = row(wrapper, label)

    if (!found) throw new Error(`De lade heeft geen veld met het label "${label}"`)

    return found.find('input')
}

/** Twice: one tick for the watcher that reacts, one for what it writes to reach the DOM. */
const pick = async (wrapper, placeholder, value) => {
    combo(wrapper, placeholder).vm.$emit('update:modelValue', value)
    await wrapper.vm.$nextTick()
    await wrapper.vm.$nextTick()
}

const pickTemplate = (wrapper, template) => pick(wrapper, 'Geen sjabloon', template?.id ?? null)
const pickCustomer = (wrapper, customer) => pick(wrapper, 'Selecteer klant', customer.id)

/** Clicking the chosen sjabloon again lets it go, the way every combobox here does. */
const deselectTemplate = (wrapper) => pickTemplate(wrapper, null)

describe('applying a contract template', () => {
    beforeEach(() => post.mockClear())

    it('fills the price, which is the field that does not bind like the others', async () => {
        const wrapper = drawer()
        await pickTemplate(wrapper, jaarcontract)

        expect(field(wrapper, 'Prijs').element.value).toBe('450,00')
    })

    it('dates the contract today and ends it a looptijd later, on the last day', async () => {
        const wrapper = drawer()
        await pickTemplate(wrapper, jaarcontract)

        const today = dayjs().format('YYYY-MM-DD')

        expect(field(wrapper, 'Startdatum').element.value).toBe(today)
        expect(field(wrapper, 'Einddatum').element.value)
            .toBe(dayjs(today).add(12, 'month').subtract(1, 'day').format('YYYY-MM-DD'))
    })

    it('leaves the end date empty for a template that runs on', async () => {
        const wrapper = drawer()
        await pickTemplate(wrapper, kaalSjabloon)

        expect(field(wrapper, 'Einddatum').element.value).toBe('')
    })

    it('holds the title until the customer is known, then fills the placeholders', async () => {
        const wrapper = drawer()
        await pickTemplate(wrapper, jaarcontract)

        const year = dayjs().format('YYYY')

        expect(field(wrapper, 'Titel').element.value).toBe(`Onderhoud ${year} — {klant}`)

        await pickCustomer(wrapper, customers[0])

        expect(field(wrapper, 'Titel').element.value).toBe(`Onderhoud ${year} — Jansen BV`)
    })

    it('follows a change of customer', async () => {
        const wrapper = drawer()
        await pickCustomer(wrapper, customers[0])
        await pickTemplate(wrapper, jaarcontract)
        await pickCustomer(wrapper, customers[1])

        expect(field(wrapper, 'Titel').element.value).toContain('De Vries')
        expect(field(wrapper, 'Titel').element.value).not.toContain('Jansen')
    })

    it('names the customer without the city the list shows behind it', async () => {
        const wrapper = drawer()
        await pickTemplate(wrapper, jaarcontract)
        await pickCustomer(wrapper, customers[0])

        expect(field(wrapper, 'Titel').element.value).not.toContain('Utrecht')
    })

    it('stops following once the title is typed in by hand', async () => {
        const wrapper = drawer()
        await pickTemplate(wrapper, jaarcontract)

        await field(wrapper, 'Titel').setValue('Eigen titel')
        await pickCustomer(wrapper, customers[0])

        expect(field(wrapper, 'Titel').element.value).toBe('Eigen titel')
    })

    it('takes the frequency mode and the generation from the template', async () => {
        const wrapper = drawer()
        await pickTemplate(wrapper, jaarcontract)

        expect(row(wrapper, 'Servicefrequentie')).toBeDefined()
        expect(row(wrapper, 'Genereerinterval')).toBeDefined()

        await pickTemplate(wrapper, kaalSjabloon)

        expect(row(wrapper, 'Servicefrequentie')).toBeUndefined()
        expect(row(wrapper, 'Genereerinterval')).toBeUndefined()
    })

    it('leaves nothing of the template picked before it', async () => {
        const wrapper = drawer()
        await pickTemplate(wrapper, jaarcontract)
        await pickTemplate(wrapper, kaalSjabloon)

        expect(field(wrapper, 'Prijs').element.value).toBe('')
        expect(field(wrapper, 'Titel').element.value).toBe('')
    })

    it('keeps what is on screen when the template is deselected', async () => {
        const wrapper = drawer()
        await pickTemplate(wrapper, jaarcontract)
        await deselectTemplate(wrapper)

        expect(field(wrapper, 'Prijs').element.value).toBe('450,00')
    })

    it('offers no sjabloon field when there are none to offer', () => {
        const wrapper = drawer({ templates: [] })

        expect(combo(wrapper, 'Geen sjabloon')).toBeUndefined()
    })

    it('drops the customer picker and names the customer it was given', () => {
        const wrapper = drawer({ customer: { id: 7, name: 'Jansen BV' } })

        expect(combo(wrapper, 'Selecteer klant')).toBeUndefined()
        expect(wrapper.findComponent(DrawerStub).props('title')).toBe('Nieuw onderhoudscontract voor Jansen BV')
    })

    it('fills the placeholders from the customer it was given', async () => {
        const wrapper = drawer({ customer: { id: 7, name: 'Jansen BV' }, customers: [] })
        await pickTemplate(wrapper, jaarcontract)

        expect(field(wrapper, 'Titel').element.value).toContain('Jansen BV')
    })

    it('starts empty again once the drawer has been closed', async () => {
        const wrapper = drawer()
        await pickTemplate(wrapper, jaarcontract)

        await wrapper.setProps({ modelValue: false })
        await wrapper.vm.$nextTick()
        await wrapper.vm.$nextTick()

        expect(field(wrapper, 'Prijs').element.value).toBe('')
        expect(field(wrapper, 'Titel').element.value).toBe('')
        expect(field(wrapper, 'Startdatum').element.value).toBe('')
    })

    it('lets the customer picker search the server when there are too many to hold', () => {
        const searching = drawer({ customersUseAjax: true })
        const holding = drawer()

        expect(combo(searching, 'Selecteer klant').props('hasExternalSearching')).toBe(true)
        expect(combo(holding, 'Selecteer klant').props('hasExternalSearching')).toBe(false)
    })

    it('posts what the template filled in', async () => {
        const wrapper = drawer()
        await pickTemplate(wrapper, jaarcontract)
        await pickCustomer(wrapper, customers[0])

        await wrapper.findAll('button').at(-1).trigger('click')

        expect(post).toHaveBeenCalledWith('/maintenancecontracts', expect.any(Object))
    })
})
