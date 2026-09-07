import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import CataloguePage from '../CataloguePage.vue'
import CollectionsPage from '../CollectionsPage.vue'
import EditPage from '../EditPage.vue'
import InvoicesPage from '../InvoicesPage.vue'
import LoginPage from '../LoginPage.vue'
import ResellersPage from '../ResellersPage.vue'

/**
 * <Head> heeft de hoofdmanager van createInertiaApp nodig en usePage() de
 * lopende pagina; allebei bestaan hier niet. De rest van de module blijft echt.
 */
vi.mock('@inertiajs/vue3', async (original) => ({
    ...(await original()),
    Head: { render: () => null },
    usePage: () => ({ props: { csrf_token: 'test', errors: {}, auth: { email: 'x@y.nl' } }, url: '/beheer' }),
}))

const tenant = {
    id: 'x', name: 'Testbedrijf', database: 'lavoro_tenant_test', subscription_started_on: '2026-09-01',
    billing_period: 'monthly', package_key: 'starter', extra_field_seats: 0, extra_office_seats: 0,
    storage_limit_gb: 50, modules: [], discount_cents: 0, discount_percent: 0, price_override_cents: null,
    invoice_address: null, invoice_email: null, invoice_postcode: null, invoice_city: null,
    vat_number: null, coc_number: null, payment_method: 'transfer', iban: null, account_holder: null,
    mandate_reference: null, mandate_signed_on: null, coupon_discount_percent: 0, coupon_discount_until: null,
}

const preview = {
    lines: [{ description: 'Abonnement Lavoro Starter', kind: 'subscription', amount_cents: 2750 }],
    subtotal_cents: 2750, discount_cents: 0, total_cents: 2750,
    vat_percent: 21, vat_cents: 578, gross_cents: 3328,
}

const screens = [
    ['de catalogus', CataloguePage, {
        packages: [{ id: 1, key: 'starter', name: 'Starter', field_seats: 1, office_seats: 1, price_cents: 2750, extra_field_cents: 1200, extra_office_cents: 800 }],
        modules: [{ id: 1, key: 'quotes', name: 'Offertes', price_cents: 2750 }],
        bundles: [{ id: 1, name: 'Offertes + Facturen', module_keys: ['quotes', 'invoices'], price_cents: 4000 }],
        settings: [{ id: 1, key: 'vat_percent', value: 21 }],
        usage: { starter: 2 },
        issuer_rows: [{ id: 1, key: 'incassant_id', value: 'NL98ZZZ' }],
    }, 'Pakketten'],

    ['de incassolijst', CollectionsPage, {
        invoices: [{ id: 1, number: '2026-LVR-1', gross_cents: 3328, tenant: { name: 'Testbedrijf', mandate_reference: 'M-1', iban: 'NL91ABNA0417164300' } }],
        issuer: { incassant_id: 'NL98ZZZ' },
        collect_on: '2026-09-15',
    }, 'Incasso'],

    ['het abonnement', EditPage, {
        tenant,
        packages: [{ key: 'starter', name: 'Starter', price_cents: 2750 }],
        modules: [{ key: 'quotes', name: 'Offertes', price_cents: 2750 }],
        ai: { spent_cents: 0, allowance_cents: 2250, is_default: true, topup_cents: 0, rate_cents: 200 },
        topups: [],
        subscription: { monthly_cents: 2750, commission_cents: 0 },
        billing: { preview, is_due: true, pending: [] },
        reseller: null,
        superadmins: [],
        unreachable: null,
    }, 'Testbedrijf'],

    ['de facturen', InvoicesPage, {
        tenant, invoices: [], preview, is_due: true, next_period_starts_on: '2026-10-01',
    }, 'Eerstvolgende factuur'],

    ['het inlogscherm', LoginPage, {}, 'Inloggen'],

    ['de resellers', ResellersPage, {
        rows: [{
            reseller: { id: 1, name: 'Wederverkoper', email: 'a@b.nl', commission_percent: 10 },
            coupons: [], tenants: [], commission: 0,
        }],
    }, 'Resellers'],
]

/**
 * Dat elk scherm overeind komt met de gegevens die de server erbij levert.
 *
 * Een bouw die slaagt zegt alleen dat het sjabloon te lezen was, niet dat het
 * werkt: een blok dat zichzelf aanroept of een slot dat niet bestaat komt er
 * probleemloos doorheen en klapt pas in de browser.
 */
describe('de beheerschermen', () => {
    it.each(screens)('%s komt overeind', (_name, page, props, expected) => {
        const wrapper = mount(page, { props, global: { stubs: { Link: true } } })

        expect(wrapper.text()).toContain(expected)
    })
})
