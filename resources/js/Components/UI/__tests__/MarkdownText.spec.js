import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import MarkdownText from '../MarkdownText.vue'

const visit = vi.fn()

vi.mock('@inertiajs/vue3', () => ({ router: { visit: (...args) => visit(...args) } }))

const render = (text) => mount(MarkdownText, { props: { text } })

describe('MarkdownText', () => {
    it('turns a list into a list instead of a wall of asterisks', () => {
        const html = render('- werkbon 12\n- werkbon 13').html()

        expect(html).toContain('<ul>')
        expect(html).toContain('<li>werkbon 12</li>')
        expect(html).not.toContain('- werkbon')
    })

    it('renders headings, emphasis and code', () => {
        const html = render('## Planning\n\n**Admin** kan `vandaag`').html()

        expect(html).toContain('<h2>Planning</h2>')
        expect(html).toContain('<strong>Admin</strong>')
        expect(html).toContain('<code>vandaag</code>')
    })

    it('keeps separate paragraphs separate', () => {
        const html = render('Eerste alinea.\n\nTweede alinea.').html()

        expect(html.match(/<p>/g)).toHaveLength(2)
    })

    /**
     * This is rendered with v-html, so the only thing standing between a model —
     * or a customer name it quoted, or a remark somebody typed a year ago — and
     * a script tag on the page is that the parser refuses to pass markup through.
     * If any of these ever render as elements, everything the assistant can read
     * becomes a way to put markup on screen.
     */
    it('never lets markup in the text become markup on the page', () => {
        const attempts = [
            '<script>alert(1)</script>',
            '<img src=x onerror="alert(1)">',
            '<iframe src="https://evil.test"></iframe>',
            'Klant <b>Acme</b> & Zonen',
            '<div onclick="alert(1)">klik</div>',
        ]

        for (const attempt of attempts) {
            const root = render(attempt).element

            expect(root.querySelector('script, img, iframe, b, div div')).toBeNull()

            // Asserting on the markup as a string would pass for the wrong
            // reason: escaped text still spells "onerror". What matters is
            // whether anything in the document actually carries the handler.
            for (const node of root.querySelectorAll('*')) {
                for (const attribute of node.attributes) {
                    expect(attribute.name.startsWith('on')).toBe(false)
                }
            }
        }
    })

    it('shows the text of a tag rather than swallowing it', () => {
        expect(render('Klant <b>Acme</b> & Zonen').text()).toContain('<b>Acme</b> & Zonen')
    })

    it('refuses a javascript link', () => {
        const wrapper = render('[klik hier](javascript:alert(1))')

        // It survives as visible text, which is fine and is not a link.
        expect(wrapper.find('a').exists()).toBe(false)
        expect(wrapper.text()).toContain('javascript:')
    })

    it('sends outside links away from the app', () => {
        const link = render('[handleiding](https://example.test/x)').find('a')

        expect(link.attributes('target')).toBe('_blank')
        expect(link.attributes('rel')).toBe('noopener noreferrer')
    })

    /**
     * A werkbon the assistant just named is where the reader wants to go, and a
     * new tab loses the conversation that led them there.
     */
    it('keeps a link to a record inside the app', async () => {
        visit.mockClear()
        const wrapper = render('Zie [#296](/serviceorders/296)')
        const link = wrapper.find('a')

        expect(link.attributes('target')).toBeUndefined()

        await link.trigger('click')

        expect(visit).toHaveBeenCalledWith('/serviceorders/296')
        expect(wrapper.emitted('navigate')).toHaveLength(1)
    })

    it('does not treat a protocol-relative link as one of ours', () => {
        expect(render('[x](//evil.test/y)').find('a').attributes('target')).toBe('_blank')
    })

    it('copes with nothing to render', () => {
        expect(render('').html()).toBeTruthy()
        expect(mount(MarkdownText).html()).toBeTruthy()
    })
})
