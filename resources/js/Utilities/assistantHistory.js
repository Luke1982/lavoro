// What goes back to the model as history: AssistantSession::REMEMBERED_EXCHANGES
// on the server, which the requests refuse more than. A follow-up needs what was
// just said rather than everything ever asked.
export const REMEMBERED_EXCHANGES = 12

// What stands in for the question on a step taken after a click; the server
// records those turns under the same words.
export const CARRIED_ON = '(verder na bevestiging)'

// AssistantSession::REMEMBERED_ANSWER_CHARS. The request refuses a longer answer
// than this, and a refusal would wedge the conversation for good: every follow-up
// would fail on history it cannot change. A long answer is worth less as context
// than the thread is worth alive.
export const REMEMBERED_ANSWER_CHARS = 8000

const ANSWER_PREFIX = 'Ik bedoel: '

/**
 * What the model is sent as the story so far, including what has been done.
 *
 * A step the assistant took by itself after a click counts as much as one
 * somebody asked for. Left out, a chain of confirmations forgets its own
 * middle: from the second click on, what the earlier clicks carried out is no
 * longer in the history, and "the action above" points at nothing.
 *
 * The eval builds the same history in PHP; tests/fixtures/assistant-history.json
 * holds both to the same result.
 */
export function historyFor(exchanges) {
    return exchanges
        .filter((exchange) => exchange.answer || exchange.pendingActions.some((action) => action.done))
        .slice(-REMEMBERED_EXCHANGES)
        .map((exchange) => ({
            question: exchange.question || CARRIED_ON,
            answer: remembered([
                exchange.answer,
                ...exchange.pendingActions
                    .filter((action) => action.done)
                    .map((action) => (action.failed ? '[niet gelukt] ' : '[al uitgevoerd] ') + action.done),
            ].join('\n\n')),
        }))
}

/**
 * Cut in characters as PHP's mb_substr and Laravel's max rule count them, not
 * in UTF-16 units: a plain slice counts an emoji twice and can split it in half.
 */
function remembered(answer) {
    return Array.from(answer).slice(0, REMEMBERED_ANSWER_CHARS).join('')
}

/** One option as it is sent back when clicked: its label with its reference. */
const optionAnswer = (option) => option.label + ' (' + (option.reference ?? '') + ')'

/** The question a click sends: every answered choice of the turn, together. */
export function choiceAnswer(options) {
    return ANSWER_PREFIX + options.map(optionAnswer).join(' en ')
}

/**
 * What each choice of a turn was answered with, read back from the question the
 * click sent, in order: the first choice's answer comes first. Read by position,
 * because two pickers can offer the same options ("Nieuw" for the customer and
 * "Nieuw" for the location), and matched as a whole segment, because a label may
 * itself hold " en " or start like another one ("Nieuw" and "Nieuwe werkbon").
 * A choice the question did not answer, and every one after it, reads as null.
 */
export function chosenOptions(choices, question) {
    if (!question?.startsWith(ANSWER_PREFIX)) return choices.map(() => null)

    let rest = question.slice(ANSWER_PREFIX.length)
    let lost = false

    return choices.map((choice) => {
        if (lost) return null

        const option = (choice.options || [])
            .filter((candidate) => rest === optionAnswer(candidate) || rest.startsWith(optionAnswer(candidate) + ' en '))
            .sort((one, other) => optionAnswer(other).length - optionAnswer(one).length)[0]

        if (!option) {
            lost = true

            return null
        }

        rest = rest.slice(optionAnswer(option).length + ' en '.length)

        return option
    })
}
