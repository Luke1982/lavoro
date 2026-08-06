import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()

vi.mock('axios', () => ({ default: { get: (...a) => get(...a), post: (...a) => post(...a) } }))
vi.mock('@inertiajs/vue3', () => ({ router: { on: () => () => {}, visit: vi.fn() } }))
vi.mock('@/Composables/useNavigator.js', () => ({ closeNavigator: vi.fn() }))

import AssistantSpotlight from '../AssistantSpotlight.vue'
import AssistantPanel from '../AssistantPanel.vue'
import { startOver, useAssistantThread } from '@/Composables/useAssistantThread.js'

/**
 * The dialog around the assistant, which is all this component still is.
 *
 * It owns the ways in and out — an event any page can fire, the shortcut,
 * escape — and one rule that is easy to lose in a refactor: closing asks the
 * panel first, because photos nobody has decided about hold the door once.
 *
 * Whether the box has actually left the screen is not asserted here. Headless
 * UI leaves on a css transition and jsdom never finishes one, so the panel stays
 * in the document long after it is closed; what can be trusted is who was asked.
 */
const spotlight = async () => {
    startOver()

    const wrapper = mount(AssistantSpotlight, {
        global: {
            stubs: {
                /** The real one wants a ResizeObserver, which jsdom does not have. */
                Dialog: { template: '<div><slot /></div>' },
                DialogPanel: { template: '<div><slot /></div>' },
            },
        },
    })

    await wrapper.vm.$nextTick()

    return wrapper
}

const settle = async (wrapper) => {
    await new Promise((resolve) => setTimeout(resolve))
    await wrapper.vm.$nextTick()
}

const shortcut = () => window.dispatchEvent(new KeyboardEvent('keydown', { key: '/', ctrlKey: true }))

describe('the assistant dialog', () => {
    beforeEach(() => {
        get.mockReset()
        post.mockReset()
        get.mockResolvedValue({ data: { conversations: [], prompts: [] } })
    })

    it('shows nothing until somebody asks for it', async () => {
        const wrapper = await spotlight()

        expect(wrapper.findComponent(AssistantPanel).exists()).toBe(false)
    })

    it('opens on the event any page can fire', async () => {
        const wrapper = await spotlight()

        window.dispatchEvent(new CustomEvent('assistant:open'))
        await settle(wrapper)

        expect(wrapper.text()).toContain('Lavoro AI')
        expect(wrapper.find('textarea').exists()).toBe(true)
    })

    it('opens on the shortcut too', async () => {
        const wrapper = await spotlight()

        shortcut()
        await settle(wrapper)

        expect(wrapper.find('textarea').exists()).toBe(true)
    })

    it('asks the panel to close rather than closing over it', async () => {
        const wrapper = await spotlight()

        window.dispatchEvent(new CustomEvent('assistant:open'))
        await settle(wrapper)

        shortcut()
        await settle(wrapper)

        expect(wrapper.findComponent(AssistantPanel).emitted('close')).toHaveLength(1)
    })

    /** Photos that were sent but not decided about hold the door for one question. */
    it('stays put while the panel still has something to ask', async () => {
        const wrapper = await spotlight()

        window.dispatchEvent(new CustomEvent('assistant:open'))
        await settle(wrapper)

        useAssistantThread().photos_sent.value = 2
        shortcut()
        await settle(wrapper)

        expect(wrapper.findComponent(AssistantPanel).emitted('close')).toBeUndefined()
        expect(wrapper.text()).toContain('In je opslag bewaren?')
    })
})
