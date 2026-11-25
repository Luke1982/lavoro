import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import process from 'node:process'
import { describe, expect, it } from 'vitest'
import { defaultStatusWith, opensWithAssistantFilter, withAssistantFilter } from '@/Utilities/assistantFilter'

const UUID = '7f9c2a4e-1b3d-4c5e-8f6a-0b1c2d3e4f5a'

describe('the assistant filter in the address', () => {
    it('is seen when the page was opened with it', () => {
        expect(opensWithAssistantFilter('?filter=' + UUID)).toBe(true)
        expect(opensWithAssistantFilter('?search=pomp&filter=' + UUID)).toBe(true)
        expect(opensWithAssistantFilter('?search=pomp')).toBe(false)
        expect(opensWithAssistantFilter('')).toBe(false)
    })

    it('goes along with a search or a change of filter', () => {
        expect(withAssistantFilter({ search: 'pomp', onlyStage: '3' }, '?filter=' + UUID))
            .toEqual({ search: 'pomp', onlyStage: '3', filter: UUID })
    })

    it('leaves the parameters alone without one', () => {
        const params = { search: 'pomp' }

        expect(withAssistantFilter(params, '?search=klep')).toBe(params)
    })

    it('never overrides a filter the visit names itself', () => {
        expect(withAssistantFilter({ filter: 'ander' }, '?filter=' + UUID)).toEqual({ filter: 'ander' })
    })

    it('reads the current address by default', () => {
        window.history.replaceState({}, '', '/serviceorders?filter=' + UUID)

        expect(opensWithAssistantFilter()).toBe(true)
        expect(withAssistantFilter({ search: 'x' })).toEqual({ search: 'x', filter: UUID })

        window.history.replaceState({}, '', '/')
    })
})

/**
 * The projects and quotes lists open on the running ones, but on every status
 * with the assistant's filter. Choosing 'Lopende' on a filtered list has to be
 * sent along on every visit, or the next search drops it and the server opens
 * every status again; and the dot for "filtered" only shows for a status the
 * person chose.
 */
describe('the lists that open on a narrower status', () => {
    it('open on every status with the assistant filter, on their own default without', () => {
        expect(defaultStatusWith('running', '?filter=' + UUID)).toBe('all')
        expect(defaultStatusWith('running', '?search=pomp')).toBe('running')
        expect(defaultStatusWith('open', '')).toBe('open')
    })

    const source = page => readFileSync(resolve(process.cwd(), `resources/js/Pages/${page}/IndexPage.vue`), 'utf8')

    it.each(['Projects', 'Quotes'])('%s sends and counts the status against that default', (page) => {
        const text = source(page)

        expect(text).toMatch(/const defaultStatus = computed\(\(\) => defaultStatusWith\(/)
        expect(text).toMatch(/status: props\.list\.status === defaultStatus\.value \? undefined : props\.list\.status/)
        expect(text).toMatch(/props\.list\.status !== defaultStatus\.value/)
    })
})

/**
 * The four lists that bring back filters this browser remembered: each skips
 * that when the assistant's filter is in the address, so the visit that restores
 * them never happens with it, and every other visit they make themselves carries
 * it along.
 */
describe('the lists that remember their filters', () => {
    const pages = ['ServiceOrders', 'Tickets', 'Products', 'Assets']

    const source = page => readFileSync(resolve(process.cwd(), `resources/js/Pages/${page}/IndexPage.vue`), 'utf8')

    const mounted = page => source(page).match(/onMounted\(\(\) => \{([\s\S]*?)\n\}\)/)

    const visits = text => [...text.matchAll(/router\.get\('\/[a-z]+',\s*([^,]+(?:\([^)]*\))?)/g)]

    it.each(pages)('%s does not restore over the assistant filter', (page) => {
        const body = mounted(page)

        expect(body).not.toBeNull()
        expect(body[1]).toMatch(/if \(opensWithAssistantFilter\(\)[^\n]*return/)
        expect(body[1].indexOf('opensWithAssistantFilter')).toBeLessThan(Math.max(body[1].indexOf('localStorage'), body[1].indexOf('recall')))
    })

    it.each(pages)('%s visits to restore only after leaving the assistant filter alone', (page) => {
        const body = mounted(page)[1]
        const leaves = body.search(/if \(opensWithAssistantFilter\(\)[^\n]*return/)

        for (const visit of visits(body)) {
            expect(visit.index).toBeGreaterThan(leaves)
            expect(visit[1]).not.toMatch(/^withAssistantFilter\(/)
        }
    })

    it.each(pages)('%s carries the filter on every other visit of its own', (page) => {
        const rest = source(page).replace(mounted(page)[0], '')

        for (const [, params] of visits(rest)) {
            expect(params).toMatch(/^withAssistantFilter\(/)
        }
    })

    it('the search box carries it too', () => {
        const search = readFileSync(resolve(process.cwd(), 'resources/js/Components/UI/SearchComponent.vue'), 'utf8')

        expect(search).toMatch(/const params = withAssistantFilter\(/)
    })
})
