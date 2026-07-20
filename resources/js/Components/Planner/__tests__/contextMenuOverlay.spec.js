import { describe, it, expect, beforeEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const projectRoot = globalThis.process.cwd()

/**
 * Regression guard for the "planner context menu whites out the whole screen" bug.
 *
 * jspreadsheet-ce/dist/jspreadsheet.css (pulled in globally by SpreadsheetComponent.vue) ships an
 * unscoped `.fullscreen { position: fixed; inset: 0; background: #fff; ... }` rule. The context-menu
 * library @imengyu/vue3-context-menu renders its overlay host as `<div class="mx-menu-ghost-host
 * fullscreen">`, so that generic rule turns the otherwise-transparent host into a solid white,
 * full-viewport box the moment a context menu opens. resources/css/app.css must neutralise it.
 */

/** Collect every top-level rule block whose selector text mentions `needle`. */
function extractRules(css, needle) {
    const rules = []
    const re = /([^{}]+)\{([^{}]*)\}/g
    let match
    while ((match = re.exec(css)) !== null) {
        if (match[1].includes(needle)) rules.push(match[0])
    }
    return rules.join('\n')
}

function inject(css) {
    const style = document.createElement('style')
    style.textContent = css
    document.head.appendChild(style)
}

const jspreadsheetCss = readFileSync(
    resolve(projectRoot, 'node_modules/jspreadsheet-ce/dist/jspreadsheet.css'),
    'utf8',
)
const appCss = readFileSync(resolve(projectRoot, 'resources/css/app.css'), 'utf8')

describe('planner context menu overlay host', () => {
    let host

    beforeEach(() => {
        document.head.innerHTML = ''
        document.body.innerHTML = ''
        // Exactly how @imengyu/vue3-context-menu builds its default overlay container.
        host = document.createElement('div')
        host.id = 'mx-menu-default-container'
        host.className = 'mx-menu-ghost-host fullscreen'
        document.body.appendChild(host)
    })

    it('would be painted solid white by the bare jspreadsheet .fullscreen rule (the bug)', () => {
        inject(extractRules(jspreadsheetCss, '.fullscreen'))
        expect(getComputedStyle(host).backgroundColor).toBe('rgb(255, 255, 255)')
    })

    it('stays transparent once app.css neutralises the collision (the fix)', () => {
        inject(extractRules(jspreadsheetCss, '.fullscreen'))
        inject(extractRules(appCss, '.mx-menu-ghost-host'))
        const bg = getComputedStyle(host).backgroundColor
        expect(['rgba(0, 0, 0, 0)', 'transparent']).toContain(bg)
    })
})
