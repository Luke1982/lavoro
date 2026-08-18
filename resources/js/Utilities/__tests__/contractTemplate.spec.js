import { describe, expect, it } from 'vitest'
import {
    CUSTOM_CONTRACT_INTERVAL, contractAutoGenerateFollowsLabel, contractIntervalLabel,
    contractTitlePlaceholderHint, fillContractTitlePlaceholders,
} from '@/Utilities/Utilities'

describe('contract title placeholders', () => {
    it('fills every token it knows', () => {
        expect(fillContractTitlePlaceholders('Onderhoud {jaar} — {klant}', {
            customerName: 'Jansen BV',
            startDate: '2026-01-01',
        })).toBe('Onderhoud 2026 — Jansen BV')
    })

    it('leaves a token standing while its value is unknown', () => {
        expect(fillContractTitlePlaceholders('{jaar} — {klant}', { startDate: '2026-03-04' }))
            .toBe('2026 — {klant}')
    })

    it('leaves a token standing when there is nothing to fill from at all', () => {
        expect(fillContractTitlePlaceholders('{klant}')).toBe('{klant}')
    })

    it('leaves a mistyped token alone instead of eating it', () => {
        expect(fillContractTitlePlaceholders('{kalnt} {jaar}', { customerName: 'X', startDate: '2026-01-01' }))
            .toBe('{kalnt} 2026')
    })

    it('ignores a date it cannot read', () => {
        expect(fillContractTitlePlaceholders('{jaar}', { startDate: 'geen datum' })).toBe('{jaar}')
    })

    it('fills a token as often as it appears', () => {
        expect(fillContractTitlePlaceholders('{klant}/{klant}', { customerName: 'X' })).toBe('X/X')
    })

    it('survives a template without a title', () => {
        expect(fillContractTitlePlaceholders(null, { customerName: 'X' })).toBe('')
    })

    it('names every token it fills in the hint under the field', () => {
        expect(contractTitlePlaceholderHint).toContain('{klant}')
        expect(contractTitlePlaceholderHint).toContain('{jaar}')
    })
})

describe('contract interval labels', () => {
    it('spells out a custom interval with its day count', () => {
        expect(contractIntervalLabel(CUSTOM_CONTRACT_INTERVAL, 45)).toBe('elke 45 dagen')
    })

    it('leaves a named interval as it is', () => {
        expect(contractIntervalLabel('Jaarlijks', null)).toBe('Jaarlijks')
    })

    it('says nothing about an interval that is not set', () => {
        expect(contractIntervalLabel(null, null)).toBe('')
    })

    it('names the frequency an empty genereerinterval will follow', () => {
        expect(contractAutoGenerateFollowsLabel(false)).toBe('Volgt de servicefrequentie')
        expect(contractAutoGenerateFollowsLabel(true)).toBe('Volgt de frequentie per machine')
    })
})
