import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('axios', () => ({
    default: { get: vi.fn() },
}))

import axios from 'axios'
import { useCustomerLocations } from '@/Composables/useCustomerLocations'

/**
 * The picker's voice. Every sentence here is one a customer once needed and did
 * not get: the box was simply empty, which reads as a location you are not
 * allowed to pick.
 */
const rustenburg = { id: 92, name: 'Rustenburg 24  - Wageningen' }
const beethovenlaan = { id: 7, name: 'Beethovenlaan 120 - Doorwerth' }

const never = () => new Promise(() => {})

describe('useCustomerLocations', () => {
    beforeEach(() => {
        axios.get.mockReset()
    })

    it('asks for a customer first, and does not go looking without one', async () => {
        const picker = useCustomerLocations()

        await picker.load(null)

        expect(axios.get).not.toHaveBeenCalled()
        expect(picker.locations.value).toEqual([])
        expect(picker.message.value).toBe('Kies eerst een klant.')
    })

    it('offers the customer their locations, and then says nothing', async () => {
        axios.get.mockResolvedValue({ data: [rustenburg] })
        const picker = useCustomerLocations()

        await picker.load(4)

        expect(axios.get).toHaveBeenCalledWith('/combo/customers/4/locations')
        expect(picker.locations.value).toEqual([rustenburg])
        expect(picker.message.value).toBe('')
    })

    it('says so when the customer has no locations yet', async () => {
        axios.get.mockResolvedValue({ data: [] })
        const picker = useCustomerLocations()

        await picker.load(4)

        expect(picker.message.value).toContain('nog geen locaties')
    })

    it('names the missing permission when the request is refused', async () => {
        axios.get.mockRejectedValue({ response: { status: 403 } })
        const picker = useCustomerLocations()

        await picker.load(4)

        expect(picker.locations.value).toEqual([])
        expect(picker.message.value).toContain('Locaties bekijken')
    })

    it('owns up to a failed request instead of looking empty', async () => {
        axios.get.mockRejectedValue(new Error('network down'))
        const picker = useCustomerLocations()

        await picker.load(4)

        expect(picker.message.value).toContain('niet worden opgehaald')
    })

    it('says it is loading while the request is in flight', async () => {
        let answer
        axios.get.mockReturnValue(new Promise((resolve) => { answer = () => resolve({ data: [rustenburg] }) }))
        const picker = useCustomerLocations()

        const loading = picker.load(4)
        expect(picker.message.value).toBe('Locaties worden geladen…')

        answer()
        await loading

        expect(picker.message.value).toBe('')
    })

    it('never leaves the previous customer on offer while the next one loads', async () => {
        axios.get.mockResolvedValue({ data: [rustenburg] })
        const picker = useCustomerLocations()
        await picker.load(4)

        axios.get.mockReturnValue(never())
        picker.load(5)

        expect(picker.locations.value).toEqual([])
    })

    it('ignores an answer that arrives after the customer has changed', async () => {
        let answerFirst
        axios.get
            .mockReturnValueOnce(new Promise((resolve) => { answerFirst = () => resolve({ data: [rustenburg] }) }))
            .mockResolvedValueOnce({ data: [beethovenlaan] })

        const picker = useCustomerLocations()
        const first = picker.load(4)
        await picker.load(5)

        answerFirst()
        await first

        expect(picker.locations.value).toEqual([beethovenlaan])
    })
})
