import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { TOURS } from '@/Tours/catalog'

/**
 * Guards the one promise the rondleidingen rest on: a storyboard only names
 * spots and actions that some component really offers.
 *
 * A spot that was renamed in a component does not crash anything — the step
 * quietly turns into a card in the middle of the screen, pointing at nothing.
 * Nobody would notice until a customer did, so the source is read here instead:
 * every `data-tour="…"`, every `:data-tour` binding and every useTour()
 * registration, and every name in every storyboard is looked up in it.
 */

const root = globalThis.process.cwd()
const sourceRoot = join(root, 'resources/js')

function sourceFiles(folder) {
    return readdirSync(folder).flatMap((name) => {
        const path = join(folder, name)
        if (statSync(path).isDirectory()) return name === '__tests__' ? [] : sourceFiles(path)

        return /\.(vue|js)$/.test(name) ? [path] : []
    })
}

/** The index just past the bracket that closes the one at `open`, skipping strings and comments. */
function closingIndex(source, open) {
    const pairs = { '(': ')', '{': '}', '[': ']' }
    const stack = []
    for (let index = open; index < source.length; index++) {
        const character = source[index]
        if (character === '/' && source[index + 1] === '/') {
            index = source.indexOf('\n', index)
            if (index === -1) return source.length
            continue
        }
        if (character === '/' && source[index + 1] === '*') {
            index = source.indexOf('*/', index) + 1
            continue
        }
        if (character === '"' || character === "'" || character === '`') {
            for (index++; index < source.length && source[index] !== character; index++) {
                if (source[index] === '\\') index++
            }
            continue
        }
        if (pairs[character]) stack.push(pairs[character])
        else if (character === stack[stack.length - 1]) {
            stack.pop()
            if (!stack.length) return index + 1
        }
    }

    return source.length
}

/** The parts of `text` separated by top-level commas. */
function topLevelParts(text) {
    const parts = []
    let start = 0
    for (let index = 0; index < text.length; index++) {
        const character = text[index]
        if ('({['.includes(character) || character === '"' || character === "'" || character === '`'
            || (character === '/' && (text[index + 1] === '/' || text[index + 1] === '*'))) {
            index = ('({['.includes(character) ? closingIndex(text, index) : skipLiteral(text, index)) - 1
            continue
        }
        if (character === ',') {
            parts.push(text.slice(start, index))
            start = index + 1
        }
    }
    parts.push(text.slice(start))

    return parts.map((part) => part.trim()).filter(Boolean)
}

function skipLiteral(text, index) {
    if (text[index] === '/' && text[index + 1] === '/') return text.indexOf('\n', index) === -1 ? text.length : text.indexOf('\n', index)
    if (text[index] === '/' && text[index + 1] === '*') return text.indexOf('*/', index) + 2
    const quote = text[index]
    for (index++; index < text.length && text[index] !== quote; index++) {
        if (text[index] === '\\') index++
    }

    return index + 1
}

function stripComments(text) {
    return text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').trim()
}

/** The keys of an object literal, given with its braces. */
function objectKeys(objectText) {
    return topLevelParts(objectText.trim().slice(1, -1))
        .map(stripComments)
        .map((entry) => entry.match(/^(?:async\s+)?(?:'([^']+)'|"([^"]+)"|([A-Za-z_$][\w$]*))/))
        .filter(Boolean)
        .map((match) => match[1] ?? match[2] ?? match[3])
}

function useTourCalls(source) {
    const calls = []
    const pattern = /\buseTour\s*\(/g
    let match
    while ((match = pattern.exec(source)) !== null) {
        if (/function\s+$/.test(source.slice(Math.max(0, match.index - 9), match.index))) continue
        const open = match.index + match[0].length - 1
        const args = topLevelParts(source.slice(open + 1, closingIndex(source, open) - 1))
        const tour = args[0]?.match(/^['"]([^'"]+)['"]$/)?.[1]
        if (!tour) continue
        const actions = args[1]?.startsWith('{') ? objectKeys(args[1]) : []
        let spots = []
        if (args[2]?.startsWith('{')) {
            const spotsEntry = topLevelParts(args[2].slice(1, -1)).find((entry) => /^spots\s*:/.test(entry))
            if (spotsEntry) spots = objectKeys(spotsEntry.replace(/^spots\s*:\s*/, ''))
        }
        calls.push({ tour, actions, spots })
    }

    return calls
}

function declarations() {
    const spots = new Map()
    const actions = new Map()
    for (const path of sourceFiles(sourceRoot)) {
        const source = readFileSync(path, 'utf8')
        const file = relative(root, path)
        for (const found of source.matchAll(/(?<![:\w-])data-tour="([^"]+)"/g)) spots.set(found[1], file)
        for (const found of source.matchAll(/:data-tour="([^"]+)"/g)) {
            for (const name of found[1].matchAll(/'([a-z][\w.-]*)'/g)) spots.set(name[1], file)
        }
        if (file.endsWith('Tours/engine.js')) continue
        for (const call of useTourCalls(source)) {
            call.actions.forEach((name) => actions.set(call.tour + ':' + name, file))
            call.spots.forEach((name) => spots.set(name, file))
        }
    }

    return { spots, actions }
}

const { spots, actions } = declarations()

const pictureSource = readFileSync(join(sourceRoot, 'Components/Tour/Aids/TourPicture.vue'), 'utf8')
const SCENES = new Set(objectKeys(pictureSource.slice(pictureSource.indexOf('const SCENES = ') + 15, closingIndex(pictureSource, pictureSource.indexOf('const SCENES = ') + 15))))
const AIDS = ['pointer', 'ghost', 'typing', 'sweep', 'picture']

function everyStep(visit) {
    for (const tour of Object.values(TOURS)) {
        for (const chapter of tour.chapters) {
            chapter.steps.forEach((step, index) => visit({ tour, chapter, step, where: tour.id + ' / ' + chapter.id + ' / stap ' + (index + 1) }))
        }
    }
}

function spotsOf(step) {
    return [
        step.spot, step.aid?.to, step.aid?.from, step.aid?.near, step.fallback?.to, step.fallback?.from, step.fallback?.near,
        ...(step.marks ?? []).map((mark) => mark.spot),
    ].filter(Boolean)
}

describe('rondleidingen', () => {
    it('finds the declarations it checks against', () => {
        expect(spots.size).toBeGreaterThan(20)
        expect(actions.has('planner:openCalendar')).toBe(true)
        expect(spots.has('planner.cell')).toBe(true)
        expect(SCENES.has('roster')).toBe(true)
    })

    it('names only spots that a component declares', () => {
        const missing = []
        everyStep(({ step, where }) => spotsOf(step).forEach((name) => {
            if (!spots.has(name)) missing.push(where + ': plek "' + name + '"')
        }))
        expect(missing).toEqual([])
    })

    it('names only actions that a component of that tour registers', () => {
        const missing = []
        for (const tour of Object.values(TOURS)) {
            for (const chapter of tour.chapters) {
                const named = [...(chapter.actions ?? []), ...chapter.steps.flatMap((step) => [step.action ?? []].flat())]
                named.forEach((name) => {
                    if (!actions.has(tour.id + ':' + name)) missing.push(tour.id + ' / ' + chapter.id + ': actie "' + name + '"')
                })
            }
        }
        expect(missing).toEqual([])
    })

    it('lights a mark at a word the voice really says', () => {
        const unspoken = []
        everyStep(({ step, where }) => (step.marks ?? []).forEach((mark) => {
            if (!mark.when || !step.say.toLowerCase().includes(mark.when.toLowerCase())) {
                unspoken.push(where + ': "' + mark.when + '" staat niet in wat er gezegd wordt')
            }
        }))
        expect(unspoken).toEqual([])
    })

    it('uses only aids and pictures that exist', () => {
        const unknown = []
        everyStep(({ step, where }) => [step.aid, step.fallback].filter(Boolean).forEach((aid) => {
            if (!AIDS.includes(aid.type)) unknown.push(where + ': hulpmiddel "' + aid.type + '"')
            if (aid.type === 'picture' && !SCENES.has(aid.scene)) unknown.push(where + ': plaatje "' + aid.scene + '"')
        }))
        expect(unknown).toEqual([])
    })

    it('gives every chapter an id, a version and steps a person can read and hear', () => {
        const problems = []
        for (const tour of Object.values(TOURS)) {
            const ids = tour.chapters.map((chapter) => chapter.id)
            if (new Set(ids).size !== ids.length) problems.push(tour.id + ': dubbele hoofdstuk-id')
            for (const chapter of tour.chapters) {
                if (!Number.isInteger(chapter.version) || chapter.version < 1) problems.push(chapter.id + ': versie')
                if (!chapter.title || !chapter.summary || !chapter.steps.length) problems.push(chapter.id + ': titel, samenvatting of stappen')
            }
        }
        everyStep(({ step, where }) => {
            if (!step.title || !step.text || !step.say) problems.push(where + ': titel, tekst of gesproken tekst ontbreekt')
        })
        expect(problems).toEqual([])
    })

    it('never reads out codes and numbers that are meant for the eye', () => {
        const spoken = []
        everyStep(({ step, where }) => {
            if (/WB-|#\d|\d{3,}|\*/.test(step.say)) spoken.push(where + ': "' + step.say + '"')
        })
        expect(spoken).toEqual([])
    })

    it('sends a call to action only to a rondleiding and chapter that exist', () => {
        const broken = []
        everyStep(({ step, where }) => {
            if (!step.cta) return
            const target = TOURS[step.cta.tour]
            if (!target || (step.cta.chapter && !target.chapters.some((chapter) => chapter.id === step.cta.chapter))) {
                broken.push(where)
            }
        })
        expect(broken).toEqual([])
    })
})
