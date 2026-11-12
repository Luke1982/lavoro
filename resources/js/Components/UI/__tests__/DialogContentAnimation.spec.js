import { describe, expect, it } from 'vitest'
import { readdirSync, readFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import process from 'node:process'

/**
 * A drawer slides in because Headless UI puts its panel off-screen and then
 * moves it. v-auto-animate reads the computed style of its element the moment
 * it mounts, which is before the panel is off-screen: the browser then takes
 * the final position as the starting point and the drawer pops in instead of
 * sliding. That happened to the machine drawer, and nothing in the code showed
 * it — only the browser did.
 *
 * So nothing written inside a drawer or a modal animates with auto-animate;
 * rows that come and go in a dialog use <Transition>. A component placed in a
 * dialog that uses it on its own elements is not seen from here.
 */
const root = join(process.cwd(), 'resources/js')

const vueFiles = (directory) => readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) return entry.name === 'node_modules' ? [] : vueFiles(path)

    return entry.name.endsWith('.vue') ? [path] : []
})

const files = vueFiles(root).map(path => ({ path, source: readFileSync(path, 'utf8') }))

const usesAutoAnimate = (source) => /v-auto-animate|useAutoAnimate/.test(source)

const dialogBlocks = (source) =>
    [...source.matchAll(/<(DrawerComponent|ModalDialog|QuickCreateModal)[\s>][\s\S]*?<\/\1>/g)].map(match => match[0])

describe('content of drawers and modals', () => {
    it('does not animate with auto-animate, which stops the dialog from sliding in', () => {
        const offenders = files.flatMap(file => dialogBlocks(file.source)
            .filter(usesAutoAnimate)
            .map(() => relative(root, file.path)))

        expect(offenders).toEqual([])
    })

    it('can still see a drawer it should complain about', () => {
        const block = dialogBlocks('<DrawerComponent v-model="open"><div v-auto-animate></div></DrawerComponent>')

        expect(block).toHaveLength(1)
        expect(usesAutoAnimate(block[0])).toBe(true)
    })
})
