import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MarkdownText from '../MarkdownText.vue'

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

    it('sends real links away from the app', () => {
        const link = render('[handleiding](https://example.test/x)').find('a')

        expect(link.attributes('target')).toBe('_blank')
        expect(link.attributes('rel')).toBe('noopener noreferrer')
    })

    it('copes with nothing to render', () => {
        expect(render('').html()).toBeTruthy()
        expect(mount(MarkdownText).html()).toBeTruthy()
    })
})
