import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import CataloguePage from '../CataloguePage.vue'
import CollectionsPage from '../CollectionsPage.vue'
import EditPage from '../EditPage.vue'
import InvoicesPage from '../InvoicesPage.vue'
import LoginPage from '../LoginPage.vue'
import ResellersPage from '../ResellersPage.vue'
import BillingCard from '@/Components/Landlord/BillingCard.vue'
import EBoekhoudenCard from '@/Components/Landlord/EBoekhoudenCard.vue'

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
    storage_limit_gb: 50, modules: [], module_prices: {}, discount_cents: 0, discount_percent: 0, price_override_cents: null,
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
        numbering: { year: 2026, prefix: '2026-LVR-', counter: 14, highest: 0 },
    }, 'Terugzetten: de volgende wordt 2026-LVR-1'],

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
        subscription: { lines: [], total_cents: 2750, commission_cents: 0 },
        billing: { demo: false, next_on: null, next: null, pending: [] },
        eboekhouden: { configured: false, relation_code: null },
        reseller: null,
        superadmins: [],
        unreachable: null,
    }, 'Testbedrijf'],

    ['de facturen', InvoicesPage, {
        tenant, invoices: [], preview, is_due: true, next_invoice_on: '2026-10-01',
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

/**
 * De eerstvolgende factuur stond er met alleen nullen en "BTW %": een
 * hulpfunctie met dezelfde naam als de prop schoof in het sjabloon voor de
 * prop. Dat het kopje er staat zegt daarover niets; de bedragen wel.
 */
describe('de facturenlijst', () => {
    it('toont de regels en bedragen van de eerstvolgende factuur', () => {
        const text = mount(InvoicesPage, {
            props: { tenant, invoices: [], preview, is_due: true, next_invoice_on: '2026-10-01' },
            global: { stubs: { Link: true } },
        }).text()

        expect(text).toContain('Abonnement Lavoro Starter')
        expect(text).toContain('BTW 21%')
        expect(text).toContain('33,28')
    })
})

/**
 * De demo wordt elke nacht opnieuw opgebouwd en dus nooit gefactureerd. De
 * kaart toonde daar alleen € 0,00, en dat leest als een fout in de prijs.
 */
describe('de facturatiekaart', () => {
    const card = (billing) => mount(BillingCard, { props: { tenant, billing }, global: { stubs: { Link: true } } })

    it('zet de volgende factuur regel voor regel neer, met wat er nog openstaat', () => {
        const text = card({
            demo: false,
            next_on: '2026-11-07',
            next: {
                lines: [{ description: 'Abonnement Lavoro Business (met 4 extra kantoorplekken) 07-11-2026 t/m 06-12-2026, normaal € 188,00, speciale prijsafspraak', amount_cents: 10000 }],
                discount_cents: 0,
                total_cents: 12500,
            },
            pending: [{ id: 3, description: 'AI-tegoed', amount_cents: 2500 }],
        }).text()

        expect(text).toContain('07-11-2026')
        expect(text).toContain('met 4 extra kantoorplekken')
        expect(text).toMatch(/AI-tegoed\s*€\s25,00\s*verwijderen/)
        expect(text).toMatch(/Totaal excl\. btw\s*€\s125,00/)
    })

    it('zegt het als er geen factuur meer komt', () => {
        const text = card({ demo: false, next_on: null, next: null, pending: [] }).text()

        expect(text).toContain('geen factuur meer gepland')
        expect(text).not.toContain('0,00')
    })

    it('zegt bij de demo waarom er niets te factureren valt', () => {
        const text = card({ demo: true, next_on: null, next: null, pending: [] }).text()

        expect(text).toContain('Wordt niet gefactureerd')
        expect(text).not.toContain('0,00')
    })
})

/**
 * Het abonnement liet alleen "Berekend" en "Totaal" zien: bij een vaste prijs van
 * € 100 en een totaal van € 128 viel niet te zien waar het verschil vandaan kwam.
 */
describe('de opbouw van de prijs', () => {
    it('zet elke regel neer met waarom hij kost wat hij kost', () => {
        const page = mount(EditPage, {
            props: {
                ...screens.find(([name]) => name === 'het abonnement')[2],
                subscription: {
                    lines: [
                        { description: 'Abonnement Lavoro Business (met 4 extra kantoorplekken)', amount_cents: 10000, detail: 'vaste prijs, normaal € 188,00' },
                        { description: 'Offertes', amount_cents: 2750 },
                    ],
                    total_cents: 12750,
                    commission_cents: 0,
                },
            },
            global: { stubs: { Link: true } },
        })

        const text = page.text()
        expect(text).toContain('vaste prijs, normaal € 188,00')
        expect(text).toMatch(/Offertes\s*€\s27,50/)
        expect(text).toMatch(/Totaal per maand\s*€\s127,50/)
    })
})

describe('de facturen en hun creditering', () => {
    const invoice = (fields) => ({
        id: 1, number: '2026-LVR-1', issued_on: '2026-11-04', gross_cents: 3328, credits_invoice_id: null,
        mailed_at: null, collected_at: null, booked_at: null, left_the_building: false, lines: [], credits: null,
        credited_by: null, ...fields,
    })
    const page = (invoices, eboekhouden = { configured: true, relation_code: 'ACC1042' }, unbilled = []) => mount(InvoicesPage, {
        props: { tenant, invoices, preview, is_due: false, next_invoice_on: '2026-12-01', eboekhouden, unbilled },
        global: { stubs: { Link: true } },
    }).text()

    it('biedt crediteren en boeken aan bij een factuur die de deur uit is', () => {
        const text = page([invoice({ mailed_at: '2026-11-04T10:00:00', left_the_building: true })])

        expect(text).toContain('crediteren')
        expect(text).toContain('naar e-Boekhouden')
        expect(text).not.toContain('verwijderen')
    })

    it('noemt bij een creditering welke factuur bij welke hoort', () => {
        const text = page([
            invoice({ id: 2, number: '2026-LVR-2', gross_cents: -3328, credits_invoice_id: 1, credits: { id: 1, number: '2026-LVR-1' } }),
            invoice({ credited_by: { id: 2, number: '2026-LVR-2' } }),
        ])

        expect(text).toContain('creditfactuur voor 2026-LVR-1')
        expect(text).toContain('gecrediteerd met 2026-LVR-2')
        expect(text).not.toContain('crediteren')
        expect(text).not.toContain('verwijderen')
    })

    it('biedt bij een factuur die nog niet de deur uit is verwijderen aan, geen crediteren', () => {
        const text = page([invoice({})])

        expect(text).toContain('verwijderen')
        expect(text).not.toContain('crediteren')
    })

    it('laat een overgeslagen of gecrediteerde periode alsnog factureren', () => {
        const text = page([], undefined, [{ start: '2026-10-01', label: '01-10-2026 t/m 31-10-2026' }])

        expect(text).toMatch(/01-10-2026 t\/m 31-10-2026\s*factureren/)
    })

    it('zegt het als de klant nog niet aan e-Boekhouden gekoppeld is', () => {
        expect(page([invoice({})], { configured: true, relation_code: null })).toContain('nog niet gekoppeld')
    })

    it('laat e-Boekhouden weg als het niet is ingesteld', () => {
        expect(page([invoice({})], { configured: false, relation_code: null })).not.toContain('e-Boekhouden')
    })
})

describe('de e-Boekhouden-kaart', () => {
    const card = (eboekhouden) => mount(EBoekhoudenCard, { props: { tenant, eboekhouden } }).text()
    const match = { code: 'ACC1042', name: 'Testbedrijf B.V.', city: 'Zwolle', email: '', coc_number: '' }

    it('zegt wat er ontbreekt als het niet is ingesteld', () => {
        expect(card({ configured: false, relation_code: null, matches: null })).toContain('Niet ingesteld')
    })

    it('laat pas na het zoeken een nieuwe relatie aanmaken', () => {
        expect(card({ configured: true, relation_code: null, matches: null })).not.toContain('Nieuwe relatie')
        expect(card({ configured: true, relation_code: null, term: 'test', matches: [match] })).toContain('Nieuwe relatie')
    })

    it('toont de gekoppelde relatie, ook zonder zoekopdracht', () => {
        const text = card({ configured: true, relation_code: 'ACC1042', matches: null })

        expect(text).toContain('ACC1042')
        expect(text).toContain('gekoppeld')
        expect(text).not.toContain('Nieuwe relatie')
    })
})
