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

const threads = (...conversations) => ({ data: { conversations } })

describe('the assistant history panel', () => {
    beforeEach(() => {
        get.mockReset()
        post.mockReset()
        get.mockResolvedValue(threads())
    })

    it('lists conversations rather than loose questions', async () => {
        get.mockResolvedValue(threads(
            { id: 'a', title: 'Ik moet een airco plaatsen', preview: 'Drie klanten gevonden.', turns: 4, last_at: null },
            { id: 'b', title: 'Welke werkbonnen staan open?', preview: 'Er staan er zeven open.', turns: 1, last_at: null },
        ))

        const wrapper = await box()
        await clickText(wrapper, 'Eerdere gesprekken')

        expect(wrapper.text()).toContain('Ik moet een airco plaatsen')
        expect(wrapper.text()).toContain('Drie klanten gevonden.')
        expect(wrapper.text()).toContain('4 berichten')
        expect(wrapper.text()).toContain('1 bericht')
    })

    it('says so plainly when nothing has been asked yet', async () => {
        const wrapper = await box()
        await clickText(wrapper, 'Eerdere gesprekken')

        expect(wrapper.text()).toContain('nog geen gesprekken')
    })

    /** A failure to look back must not read as a failure of the assistant. */
    it('reports its own failure inside the panel', async () => {
        get.mockRejectedValue({ response: { data: { message: 'Kapot.' } } })

        const wrapper = await box()
        await clickText(wrapper, 'Eerdere gesprekken')

        expect(wrapper.text()).toContain('Kapot.')
    })

    /**
     * Filling the box with the opening question threw the thread away and left
     * somebody to type their way back to where they had been.
     */
    it('opens the conversation again rather than retyping its first question', async () => {
        get.mockImplementation((url) => Promise.resolve(
            url === '/assistant/history'
                ? threads({ id: 'a', title: 'Wie kan er dinsdag?', preview: 'Jeremy.', turns: 2, last_at: null })
                : {
                    data: {
                        id: 'a',
                        turns: [
                            { question: 'Wie kan er dinsdag?', answer: 'Jeremy en Kenneth.', failure: null, tools: ['find_available_technician'] },
                            { question: 'En woensdag?', answer: 'Alleen Kenneth.', failure: null, tools: [] },
                        ],
                    },
                }
        ))

        const wrapper = await box()
        await clickText(wrapper, 'Eerdere gesprekken')
        await clickText(wrapper, 'Wie kan er dinsdag?')

        expect(wrapper.text()).toContain('Jeremy en Kenneth.')
        expect(wrapper.text()).toContain('En woensdag?')
        expect(wrapper.text()).toContain('Alleen Kenneth.')
        expect(wrapper.find('textarea').element.value).toBe('')
        expect(post).not.toHaveBeenCalled()
    })

    it('goes back to the conversation without asking anything', async () => {
        const wrapper = await box()
        await clickText(wrapper, 'Eerdere gesprekken')
        await clickText(wrapper, 'Terug')

        expect(post).not.toHaveBeenCalled()
        expect(wrapper.text()).not.toContain('nog geen gesprekken')
    })

    /**
     * Every turn has to carry the same thread id, or the stored turns come back as
     * loose questions again — which is the whole fault this replaced.
     */
    it('sends one conversation id for the whole thread', async () => {
        post.mockResolvedValue({ data: { answer: 'Ja.', tools: [], pending: [], choices: [] } })

        const wrapper = await box()
        const field = wrapper.find('textarea')

        await field.setValue('Eerste vraag')
        await field.trigger('keydown', { key: 'Enter' })
        await new Promise((r) => setTimeout(r, 10))

        await field.setValue('Tweede vraag')
        await field.trigger('keydown', { key: 'Enter' })
        await new Promise((r) => setTimeout(r, 10))

        const asks = post.mock.calls.filter(([url]) => url === '/assistant/ask')

        expect(asks).toHaveLength(2)
        expect(asks[0][1].conversation).toBeTruthy()
        expect(asks[1][1].conversation).toBe(asks[0][1].conversation)
    })

    /**
     * Always there, whatever the answer says. An assistant that sounds certain is
     * the one worth doubting, and this costs nothing on the turns where it is
     * right.
     */
    it('always says the assistant can be wrong', async () => {
        const wrapper = await box()

        expect(wrapper.text()).toContain('De AI assistent kan fouten maken, controleer de gegevens altijd')
    })

})
