import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { router } from '@inertiajs/vue3'
import IndexPage from '../IndexPage.vue'

/**
 * Het overzicht hoort zichzelf bij te houden.
 *
 * Dit is drie keer misgegaan, en elke keer omdat het verversen aan een
 * voorwaarde hing die niet klopte. Deze test kijkt naar het enige dat telt: gaat
 * er vanzelf een verzoek naar de server, en vraagt dat alleen om wat er kan
 * veranderen.
 */
describe('het beheeroverzicht', () => {
    const props = {
        rows: [{
            id: 'x', name: 'Testbedrijf', database: 'lavoro_tenant_test', package: 'starter',
            busy: false, broken: null, field: 0, field_limit: 1, office: 1, office_limit: 1,
            used_gb: 0, storage_limit_gb: 50, total: 2750,
        }],
        monthly: 2750,
        requests: [],
        passwords: [],
        packages: [{ key: 'starter', name: 'Starter', price_cents: 2750 }],
        modules: [{ key: 'quotes', name: 'Offertes', price_cents: 2750 }],
    }

    beforeEach(() => {
        vi.useFakeTimers()
        vi.spyOn(router, 'reload').mockImplementation(() => {})
    })

    afterEach(() => {
        vi.useRealTimers()
        vi.restoreAllMocks()
    })

    it('haalt zichzelf op zonder dat er iets loopt', async () => {
        mount(IndexPage, { props, global: { stubs: { Link: true } } })

        expect(router.reload).not.toHaveBeenCalled()

        await vi.advanceTimersByTimeAsync(3500)

        expect(router.reload).toHaveBeenCalled()
    })

    it('vraagt alleen om wat kan veranderen', async () => {
        mount(IndexPage, { props, global: { stubs: { Link: true } } })

        await vi.advanceTimersByTimeAsync(3500)

        const options = router.reload.mock.calls[0][0]

        expect(options.only).toEqual(['rows', 'requests', 'passwords', 'monthly'])
        expect(options.preserveScroll).toBe(true)
    })

    it('blijft doorvragen', async () => {
        mount(IndexPage, { props, global: { stubs: { Link: true } } })

        await vi.advanceTimersByTimeAsync(10000)

        expect(router.reload.mock.calls.length).toBeGreaterThanOrEqual(3)
    })
})
