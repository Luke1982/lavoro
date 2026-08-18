import { describe, it, expect } from 'vitest'
import { syncRequestedList } from '@/Components/Tickets/infoRequestBody'

const KNOWN = ["foto's van de storing", "video's van de storing", 'andere aanvullende informatie']

describe('syncRequestedList', () => {
    it('adds the list under the letter when there is none yet', () => {
        const result = syncRequestedList('<p>Beste klant,</p>', ["foto's van de storing"], KNOWN)

        expect(result).toContain('<p>Beste klant,</p>')
        expect(result).toContain("<li>foto's van de storing</li>")
        expect(result.match(/<ul/g)).toHaveLength(1)
    })

    it('rewrites the existing list without touching the text around it', () => {
        const before = syncRequestedList('<p>Beste klant,</p><p>Groet</p>', KNOWN.slice(0, 2), KNOWN)
        const after = syncRequestedList(before, ["video's van de storing"], KNOWN)

        expect(after).toContain('<p>Beste klant,</p>')
        expect(after).toContain('<p>Groet</p>')
        expect(after).toContain("<li>video's van de storing</li>")
        expect(after).not.toContain("<li>foto's van de storing</li>")
        expect(after.match(/<ul/g)).toHaveLength(1)
    })

    it('recognises its own list again after the editor has stripped the marker', () => {
        const stripped = "<p>Beste klant,</p><ul><li>foto's van de storing</li></ul>"

        const result = syncRequestedList(stripped, KNOWN, KNOWN)

        expect(result.match(/<ul/g)).toHaveLength(1)
        expect(result.match(/<li/g)).toHaveLength(3)
    })

    it('leaves a list the writer made themselves alone', () => {
        const own = '<p>Beste klant,</p><ul><li>Eigen aantekening</li></ul>'

        const result = syncRequestedList(own, ["foto's van de storing"], KNOWN)

        expect(result).toContain('<li>Eigen aantekening</li>')
        expect(result.match(/<ul/g)).toHaveLength(2)
    })

    it('removes the list when nothing is asked for', () => {
        const before = syncRequestedList('<p>Beste klant,</p>', KNOWN, KNOWN)

        expect(syncRequestedList(before, [], KNOWN)).toBe('<p>Beste klant,</p>')
    })

    it('keeps the order of the buttons rather than the order of the clicks', () => {
        const result = syncRequestedList('<p>Beste klant,</p>', KNOWN, KNOWN)
        const items = [...result.matchAll(/<li>(.*?)<\/li>/g)].map((match) => match[1])

        expect(items).toEqual(KNOWN)
    })
})
