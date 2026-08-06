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

/**
 * A turn can carry two pickers — which product, which werkbon — and firing on
 * the first click shipped half an answer and killed the other question unasked.
 */
describe('answering pickers', () => {
    beforeEach(() => {
        get.mockReset()
        post.mockReset()
        get.mockResolvedValue({ data: { conversations: [] } })
    })

    const answerWith = (choices) =>
        post.mockResolvedValue({ data: { answer: 'Kies maar.', tools: [], choices } })

    const sendQuestion = async (wrapper, text) => {
        await wrapper.find('textarea').setValue(text)
        await wrapper.find('textarea').trigger('keydown', { key: 'Enter' })
        await new Promise((resolve) => setTimeout(resolve))
        await wrapper.vm.$nextTick()
    }

    const clickOption = async (wrapper, label) => {
        await wrapper.findAll('button').find((b) => b.text() === label).trigger('click')
        await new Promise((resolve) => setTimeout(resolve))
        await wrapper.vm.$nextTick()
    }

    it('waits for every picker of the turn before sending', async () => {
        answerWith([
            { question: 'Welk product?', options: [{ label: 'ZS-W', reference: 'product 2', link: null }, { label: 'ZS-WF', reference: 'product 6', link: null }] },
            { question: 'Welke werkbon?', options: [{ label: 'Werkbon #4', reference: 'werkbon 4', link: null }, { label: 'Werkbon #324', reference: 'werkbon 324', link: null }] },
        ])

        const wrapper = await box()
        await sendQuestion(wrapper, 'Plan drie units in')

        expect(post).toHaveBeenCalledTimes(1)

        await clickOption(wrapper, 'ZS-WF')

        expect(post).toHaveBeenCalledTimes(1)
        expect(wrapper.text()).toContain('Gekozen: ZS-WF')

        await clickOption(wrapper, 'Werkbon #4')

        expect(post).toHaveBeenCalledTimes(2)
        expect(post.mock.calls[1][1].question).toContain('ZS-WF (product 6)')
        expect(post.mock.calls[1][1].question).toContain('Werkbon #4 (werkbon 4)')
    })

    it('still sends straight away when the turn asks one thing', async () => {
        answerWith([
            { question: 'Welke klant?', options: [{ label: 'Majorlabel', reference: 'klant 2', link: null }, { label: 'Kreeft', reference: 'klant 4', link: null }] },
        ])

        const wrapper = await box()
        await sendQuestion(wrapper, 'Zoek de klant')
        await clickOption(wrapper, 'Majorlabel')

        expect(post).toHaveBeenCalledTimes(2)
        expect(post.mock.calls[1][1].question).toBe('Ik bedoel: Majorlabel (klant 2)')
    })

    it('retires a picker once something else is asked instead', async () => {
        answerWith([
            { question: 'Welke klant?', options: [{ label: 'Majorlabel', reference: 'klant 2', link: null }, { label: 'Kreeft', reference: 'klant 4', link: null }] },
        ])

        const wrapper = await box()
        await sendQuestion(wrapper, 'Zoek de klant')

        answerWith([])
        await sendQuestion(wrapper, 'Laat maar, iets anders')

        expect(wrapper.text()).toContain('Vervallen')

        const leftover = wrapper.findAll('button').find((b) => b.text() === 'Majorlabel')
        expect(leftover).toBeUndefined()
    })
})

/**
 * Three exchanges in, the opener is off the top of the scroll and every answer
 * below reads without its context — which klant, which klus. So it rides along.
 */
describe('the pinned opening question', () => {
    beforeEach(() => {
        get.mockReset()
        post.mockReset()
        get.mockResolvedValue({ data: { conversations: [] } })
        post.mockResolvedValue({ data: { answer: 'Goed.', tools: [], choices: [] } })
    })

    const sendQuestion = async (wrapper, text) => {
        await wrapper.find('textarea').setValue(text)
        await wrapper.find('textarea').trigger('keydown', { key: 'Enter' })
        await new Promise((resolve) => setTimeout(resolve))
        await wrapper.vm.$nextTick()
    }

    it('keeps the first question on top, whatever comes after', async () => {
        const wrapper = await box()

        await sendQuestion(wrapper, 'Drie aircos plaatsen bij Majorlabel')
        await sendQuestion(wrapper, 'Doe de ZS-WF maar')

        const pinned = wrapper.find('p[title]')

        expect(pinned.exists()).toBe(true)
        expect(pinned.text()).toBe('Drie aircos plaatsen bij Majorlabel')
        expect(pinned.classes()).toContain('sticky')
    })

    it('shows nothing before anything has been asked', async () => {
        const wrapper = await box()

        expect(wrapper.find('p[title]').exists()).toBe(false)
    })
})

/**
 * Reporting asks why first. The transcript says what happened; only the melder
 * can say what should have happened instead.
 */
describe('reporting with a reason', () => {
    beforeEach(() => {
        get.mockReset()
        post.mockReset()
        get.mockResolvedValue({ data: { conversations: [] } })
        post.mockResolvedValue({ data: { answer: 'Goed.', tools: [], choices: [] } })
    })

    const sendQuestion = async (wrapper, text) => {
        await wrapper.find('textarea').setValue(text)
        await wrapper.find('textarea').trigger('keydown', { key: 'Enter' })
        await new Promise((resolve) => setTimeout(resolve))
        await wrapper.vm.$nextTick()
    }

    const clickText = async (wrapper, text) => {
        await wrapper.findAll('button').find((b) => b.text().includes(text)).trigger('click')
        await new Promise((resolve) => setTimeout(resolve))
        await wrapper.vm.$nextTick()
    }

    it('asks why before it sends anything', async () => {
        const wrapper = await box()
        await sendQuestion(wrapper, 'Plan iets in')

        await clickText(wrapper, 'Gesprek melden')

        expect(post).toHaveBeenCalledTimes(1)
        expect(wrapper.find('#report-reason').exists()).toBe(true)

        await wrapper.find('#report-reason').setValue('Hij noemde de verkeerde klant')
        post.mockResolvedValue({ data: { message: 'Opgeslagen en gemaild.' } })
        await clickText(wrapper, 'Verstuur melding')

        const sent = post.mock.calls.at(-1)
        expect(sent[0]).toBe('/assistant/report')
        expect(sent[1].reason).toBe('Hij noemde de verkeerde klant')
        expect(wrapper.find('#report-reason').exists()).toBe(false)
    })

    it('sends null rather than an empty sentence', async () => {
        const wrapper = await box()
        await sendQuestion(wrapper, 'Plan iets in')

        await clickText(wrapper, 'Gesprek melden')
        post.mockResolvedValue({ data: { message: 'Opgeslagen.' } })
        await clickText(wrapper, 'Verstuur melding')

        expect(post.mock.calls.at(-1)[1].reason).toBeNull()
    })
})

/**
 * Half of what this assistant can do is invisible from a blank prompt box —
 * nobody guesses it can read a typeplaatje off photos already on a werkbon.
 */
describe('the questions panel', () => {
    beforeEach(() => {
        get.mockReset()
        post.mockReset()
        post.mockResolvedValue({ data: { answer: 'Goed.', tools: [], choices: [] } })
    })

    const withPrompts = (prompts) =>
        get.mockImplementation((url) =>
            url.startsWith('/assistant/prompts')
                ? Promise.resolve({ data: { prompts } })
                : Promise.resolve({ data: { conversations: [] } }))

    it('offers the questions for this page while nothing has been asked', async () => {
        withPrompts([{ id: 1, label: "Foto's uitlezen", question: 'Kijk naar de foto’s', mine: false }])

        const wrapper = await box()

        expect(wrapper.text()).toContain("Foto's uitlezen")
        expect(get.mock.calls.some(([url, config]) =>
            url === '/assistant/prompts' && config?.params?.context)).toBe(true)
    })

    it('asks the saved question when its button is clicked', async () => {
        withPrompts([{ id: 1, label: 'Tijdelijke machines', question: 'Welke machines staan als onbekend?', mine: false }])

        const wrapper = await box()
        await wrapper.findAll('button').find((b) => b.text() === 'Tijdelijke machines').trigger('click')
        await new Promise((resolve) => setTimeout(resolve))

        expect(post.mock.calls.at(-1)[1].question).toBe('Welke machines staan als onbekend?')
    })

    it('leaves the panel behind once the conversation starts', async () => {
        withPrompts([{ id: 1, label: "Foto's uitlezen", question: 'Kijk', mine: false }])

        const wrapper = await box()
        await wrapper.find('textarea').setValue('Iets anders')
        await wrapper.find('textarea').trigger('keydown', { key: 'Enter' })
        await new Promise((resolve) => setTimeout(resolve))
        await wrapper.vm.$nextTick()

        expect(wrapper.text()).not.toContain('Veelgestelde vragen')
    })
})

/**
 * Grouped by tool call — which is how they arrive — a set read in two batches
 * drew a box with an outdoor unit and one indoor unit, then a box with a second
 * indoor unit and an unrelated Tosot.
 */
describe('findings grouped by machine', () => {
    beforeEach(() => {
        get.mockReset()
        post.mockReset()
        get.mockResolvedValue({ data: { conversations: [] } })
    })

    it('puts one machine in one box however the readings arrived', async () => {
        post.mockResolvedValue({
            data: {
                answer: 'Gevonden.',
                tools: [],
                choices: [],
                findings: [
                    { subject: 'buitenunit', field: 'model', value: 'SCM80ZS-W', confidence: 95 },
                    { subject: 'binnenunit 1', field: 'model', value: 'SRK35ZS-WF', confidence: 95 },
                    { subject: 'buitenunit', field: 'serienummer', value: '654600682CE', confidence: 90 },
                ],
                unreadable: ['Tosot typeplaatje'],
            },
        })

        const wrapper = await box()
        await wrapper.find('textarea').setValue('Lees de plaatjes')
        await wrapper.find('textarea').trigger('keydown', { key: 'Enter' })
        await new Promise((resolve) => setTimeout(resolve))
        await wrapper.vm.$nextTick()

        const boxes = wrapper.findAll('.bg-slate-50').filter((node) => node.text().includes('%'))

        expect(boxes).toHaveLength(2)
        expect(boxes[0].text()).toContain('buitenunit')
        expect(boxes[0].text()).toContain('SCM80ZS-W')
        expect(boxes[0].text()).toContain('654600682CE')
        expect(boxes[1].text()).toContain('SRK35ZS-WF')
        expect(wrapper.text()).toContain('Tosot typeplaatje')
    })

    it('keeps the surer reading when a field is reported twice', async () => {
        post.mockResolvedValue({
            data: {
                answer: 'Gevonden.',
                tools: [],
                choices: [],
                findings: [
                    // The surer one first, so "last wins" would show the vague one.
                    { subject: 'buitenunit', field: 'model', value: 'SCM80ZS-W', confidence: 95 },
                    { subject: 'buitenunit', field: 'model', value: 'onduidelijk', confidence: 40 },
                ],
            },
        })

        const wrapper = await box()
        await wrapper.find('textarea').setValue('Lees')
        await wrapper.find('textarea').trigger('keydown', { key: 'Enter' })
        await new Promise((resolve) => setTimeout(resolve))
        await wrapper.vm.$nextTick()

        expect(wrapper.text()).toContain('SCM80ZS-W')
        expect(wrapper.text()).not.toContain('onduidelijk')
    })
})

/**
 * The cost of a photo is invisible at the moment somebody attaches one: a turn
 * with six of them measured sixty times a turn without.
 */
describe('the photo cost warning', () => {
    beforeEach(() => {
        get.mockReset()
        post.mockReset()
        get.mockResolvedValue({ data: { conversations: [] } })
        post.mockResolvedValue({ data: { answer: 'Goed.', tools: [], choices: [] } })
    })

    const ask = async (wrapper, text) => {
        await wrapper.find('textarea').setValue(text)
        await wrapper.find('textarea').trigger('keydown', { key: 'Enter' })
        await new Promise((resolve) => setTimeout(resolve))
        await wrapper.vm.$nextTick()
    }

    it('says nothing on a conversation without photos', async () => {
        const wrapper = await box()
        await ask(wrapper, 'Wie is klant 12?')

        expect(wrapper.text()).not.toContain('tientallen keren')
    })

    /** A tool that fetched the photos already on a record costs just as much. */
    it('warns when a tool went and looked at photos', async () => {
        post.mockResolvedValue({
            data: {
                answer: 'Gevonden.',
                tools: [{ name: 'view_images', arguments: {}, failed: false }],
                choices: [],
            },
        })

        const wrapper = await box()
        await ask(wrapper, "Kijk naar de foto's bij deze werkbon")

        expect(wrapper.text()).toContain('tientallen keren')
    })

    it('keeps warning while photos stay parked with the conversation', async () => {
        post.mockResolvedValue({ data: { answer: 'Goed.', tools: [], choices: [] } })

        const wrapper = await box()
        wrapper.vm.pending_images.push('data:image/png;base64,AAAA')
        await wrapper.vm.$nextTick()

        expect(wrapper.text()).toContain('tientallen keren')

        await ask(wrapper, 'Wat is dit?')

        expect(wrapper.text()).toContain('tientallen keren')
    })
})
