import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('axios', () => ({
    default: {
        get: vi.fn(() => Promise.resolve({ data: { remarks: [{ id: 1 }], images: [{ id: 2 }] } })),
    },
}))

import axios from 'axios'
import { useEventFeedback } from '@/Composables/useEventFeedback'

describe('useEventFeedback', () => {
    beforeEach(() => axios.get.mockClear())

    it('loads feedback and opens on openFeedback', async () => {
        const fb = useEventFeedback()
        expect(fb.open.value).toBe(false)

        await fb.openFeedback({ id: 42, name: 'Bezoek' })

        expect(axios.get).toHaveBeenCalledWith('/api/events/42/feedback')
        expect(fb.open.value).toBe(true)
        expect(fb.activeEvent.value.id).toBe(42)
        expect(fb.remarks.value).toHaveLength(1)
        expect(fb.images.value).toHaveLength(1)
    })

    it('mutating handlers update lists and bump changed', async () => {
        const fb = useEventFeedback()
        await fb.openFeedback({ id: 42 })
        const before = fb.changed.value

        fb.onRemarkCreated({ id: 9 })
        expect(fb.remarks.value.some(r => r.id === 9)).toBe(true)

        fb.onRemarkDeleted(9)
        expect(fb.remarks.value.some(r => r.id === 9)).toBe(false)

        fb.onImageDeleted(2)
        expect(fb.images.value.some(i => i.id === 2)).toBe(false)

        expect(fb.changed.value).toBeGreaterThan(before)
    })
})
