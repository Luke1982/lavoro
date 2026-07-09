import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import PlannerLoadingOverlay from '@/Components/Planner/PlannerLoadingOverlay.vue'

// Regression: MobilePlannerView is mounted both on the dedicated Planner page (inside a
// `h-screen` flex column, which gives PlannerLoadingOverlay a definite height) and embedded
// on the Dashboard (inside a plain, height:auto block wrapper). If the scrollable content
// passed into PlannerLoadingOverlay's slot is `position: absolute`, it never contributes to
// its parent's auto-height and requires a *definite* ancestor height to size itself. On the
// Planner page that's fine (flex gives it one); on the Dashboard the wrapper's height is
// `auto`, so the absolutely-positioned content — and with it the whole timeline — collapsed
// to 0x0 and silently rendered nothing, even though the events had loaded correctly.
//
// jsdom doesn't run real layout, so this can't be caught by asserting computed sizes. Instead
// we assert the source never reintroduces `position: absolute` for the scrollable content —
// it must stay an in-flow child (`h-full`), which fills a definite parent height exactly and
// gracefully degrades to content-sized when the parent's height is auto.
describe('PlannerLoadingOverlay scroll-container regression', () => {
    const plannerDir = resolve(dirname(fileURLToPath(import.meta.url)), '..')

    it.each([
        ['ResourcePlannerWidget.vue', /ref="gridScrollRef"/],
        ['MobilePlannerView.vue', /overflow-y-auto/],
    ])('%s keeps its PlannerLoadingOverlay slot content in-flow (h-full, not absolute inset-0)', (file, marker) => {
        const source = readFileSync(resolve(plannerDir, file), 'utf-8')
        const line = source.split('\n').find((l) => marker.test(l))

        expect(line, `expected to find the scroll container line in ${file}`).toBeTruthy()
        expect(line).toMatch(/\bh-full\b/)
        expect(line).not.toMatch(/\babsolute\b/)
        expect(line).not.toMatch(/\binset-0\b/)
    })
})

describe('PlannerLoadingOverlay', () => {
    it('renders slot content in-flow (not inside an absolutely-positioned wrapper of its own)', () => {
        const wrapper = mount(PlannerLoadingOverlay, {
            props: { loading: false },
            slots: { default: '<div class="my-scroll-area h-full overflow-auto">content</div>' },
        })

        const root = wrapper.element
        expect(root.className).toContain('relative')
        expect(root.className).not.toContain('absolute')

        const slotted = wrapper.find('.my-scroll-area')
        expect(slotted.exists()).toBe(true)
        expect(slotted.element.parentElement).toBe(root)
    })

    it('shows the centered spinner overlay only while loading', async () => {
        const wrapper = mount(PlannerLoadingOverlay, { props: { loading: false } })
        expect(wrapper.text()).not.toContain('Afspraken worden geladen')

        await wrapper.setProps({ loading: true })
        expect(wrapper.text()).toContain('Afspraken worden geladen')
    })
})
