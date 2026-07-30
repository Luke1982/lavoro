import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()

vi.mock('axios', () => ({ default: { get: (...a) => get(...a), post: (...a) => post(...a) } }))
vi.mock('@inertiajs/vue3', () => ({ router: { on: () => () => {}, visit: vi.fn() } }))
vi.mock('@/Composables/useNavigator.js', () => ({ closeNavigator: vi.fn() }))

import AssistantSpotlight from '../AssistantSpotlight.vue'

/**
 * Looking back at what was asked before.
 *
 * These existed in the database and in the arrow keys and nowhere a person could
 * see, so "where can I find my conversation history" had no answer at all.
 */
const box = async () => {
    const wrapper = mount(AssistantSpotlight, {
        global: {
            stubs: {
                TransitionRoot: { template: '<div><slot /></div>' },
                TransitionChild: { template: '<div><slot /></div>' },
                Dialog: { template: '<div><slot /></div>' },
                DialogPanel: { template: '<div><slot /></div>' },
            },
        },
    })

    window.dispatchEvent(new CustomEvent('assistant:open'))
    await wrapper.vm.$nextTick()

    return wrapper
}

const clickText = async (wrapper, text) => {
    const button = wrapper.findAll('button').find((b) => b.text().includes(text))
    await button.trigger('click')
    await wrapper.vm.$nextTick()
    await wrapper.vm.$nextTick()
}

describe('the assistant history panel', () => {
    beforeEach(() => {
        get.mockReset()
        post.mockReset()
        get.mockResolvedValue({ data: { questions: [] } })
    })

    it('shows what was asked before', async () => {
        get.mockResolvedValue({
            data: {
                questions: [
                    { id: 2, question: 'Wie kan er dinsdag?', answer: 'Jeremy en Kenneth.', answer_truncated: false, asked_at: null },
                    { id: 1, question: 'Welke werkbonnen staan open?', answer: 'Er staan er zeven open.', answer_truncated: false, asked_at: null },
                ],
            },
        })

        const wrapper = await box()
        await clickText(wrapper, 'Eerder gevraagd')

        expect(wrapper.text()).toContain('Wie kan er dinsdag?')
        expect(wrapper.text()).toContain('Welke werkbonnen staan open?')
        expect(wrapper.text()).toContain('Jeremy en Kenneth.')
    })

    it('says so plainly when nothing has been asked yet', async () => {
        const wrapper = await box()
        await clickText(wrapper, 'Eerder gevraagd')

        expect(wrapper.text()).toContain('Je hebt nog niets gevraagd')
    })

    /** A failure to look back must not read as a failure of the assistant. */
    it('reports its own failure inside the panel', async () => {
        get.mockRejectedValue({ response: { data: { message: 'Kapot.' } } })

        const wrapper = await box()
        await clickText(wrapper, 'Eerder gevraagd')

        expect(wrapper.text()).toContain('Kapot.')
    })

    /**
     * The old answer described the state of things then. Showing it as current is
     * how somebody acts on a werkbon that has since moved on, so clicking puts the
     * question back to be asked again rather than replaying what it said.
     */
    it('puts an old question back in the box instead of replaying its answer', async () => {
        get.mockResolvedValue({
            data: { questions: [{ id: 1, question: 'Wie kan er dinsdag?', answer: 'Jeremy.', answer_truncated: false, asked_at: null }] },
        })

        const wrapper = await box()
        await clickText(wrapper, 'Eerder gevraagd')
        await clickText(wrapper, 'Wie kan er dinsdag?')

        expect(wrapper.find('textarea').element.value).toBe('Wie kan er dinsdag?')
        expect(post).not.toHaveBeenCalled()
    })

    it('goes back to the conversation without asking anything', async () => {
        const wrapper = await box()
        await clickText(wrapper, 'Eerder gevraagd')
        await clickText(wrapper, 'Terug')

        expect(post).not.toHaveBeenCalled()
        expect(wrapper.text()).not.toContain('Je hebt nog niets gevraagd')
    })
})
