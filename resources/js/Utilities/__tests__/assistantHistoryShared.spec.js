import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { CARRIED_ON, REMEMBERED_ANSWER_CHARS, REMEMBERED_EXCHANGES, choiceAnswer, chosenOptions, historyFor } from '@/Utilities/assistantHistory'

// The same cases the eval's PHP runs, from the same file. The eval plays whole
// conversations against the model, and is only worth something while the history
// it sends is the history the box sends.

const here = dirname(fileURLToPath(import.meta.url))
const fixture = JSON.parse(readFileSync(join(here, '../../../../tests/fixtures/assistant-history.json'), 'utf8'))

/** The fixture says what was done per exchange; the panel keeps it on its actions. */
const asPanelExchange = (exchange) => ({
    question: exchange.question,
    answer: exchange.answer,
    pendingActions: exchange.done.map((done) => ({ done: done.text, failed: done.failed })),
})

describe('the history sent back to the model', () => {
    it('keeps the limits the eval keeps', () => {
        expect(REMEMBERED_EXCHANGES).toBe(fixture.exchanges)
        expect(REMEMBERED_ANSWER_CHARS).toBe(fixture.answer_chars)
        expect(CARRIED_ON).toBe(fixture.carried_on)
    })

    for (const testCase of fixture.cases) {
        it(testCase.name, () => {
            expect(historyFor(testCase.exchanges.map(asPanelExchange))).toEqual(testCase.expected)
        })
    }
})

describe('what a click on options sends', () => {
    for (const testCase of fixture.choices) {
        it(testCase.name, () => {
            const picked = testCase.choices.map((choice, index) => choice.options[testCase.picked[index]])

            expect(choiceAnswer(picked)).toBe(testCase.expected)
            expect(chosenOptions(testCase.choices, testCase.expected)).toEqual(picked)
        })
    }
})

describe('a choice read back from what a click sent', () => {
    const choice = { options: [{ label: 'Nieuw', reference: 'nieuw' }, { label: 'Bestaand', reference: 'bestaand' }] }

    it('finds the option that was sent', () => {
        const sent = choiceAnswer([{ label: 'Bestaand', reference: 'bestaand' }])

        expect(chosenOptions([choice], sent)[0]?.label).toBe('Bestaand')
    })

    it('does not find a label inside another answer', () => {
        const sent = choiceAnswer([{ label: 'Nieuwe werkbon', reference: 'werkbon' }])

        expect(chosenOptions([choice], sent)).toEqual([null])
    })

    it('reads nothing from a typed question', () => {
        expect(chosenOptions([choice], 'Nieuw graag')).toEqual([null])
    })

    it('reads a choice it cannot place, and every one after it, as unanswered', () => {
        const sent = choiceAnswer([{ label: 'Onbekend', reference: 'x' }, { label: 'Nieuw', reference: 'nieuw' }])

        expect(chosenOptions([choice, choice], sent)).toEqual([null, null])
    })
})
