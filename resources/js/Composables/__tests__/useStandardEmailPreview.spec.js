import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('axios', () => ({
    default: {
        get: vi.fn(() => Promise.resolve({
            data: { standard_email_id: 7, to: 'klant@example.com', subject: 'Onderwerp', body: '<p>Body</p>' },
        })),
    },
}))

import axios from 'axios'
import { useStandardEmailPreview } from '@/Composables/useStandardEmailPreview'

describe('useStandardEmailPreview', () => {
    beforeEach(() => axios.get.mockClear())

    it('starts closed', () => {
        const { previewModal } = useStandardEmailPreview()
        expect(previewModal.value.open).toBe(false)
    })

    it('openPreview fetches the rendered preview and populates + opens the modal', async () => {
        const { previewModal, previewModalKey, openPreview } = useStandardEmailPreview()
        const keyBefore = previewModalKey.value

        await openPreview(42, 7, { trigger: 'event_created', editable: false })

        expect(axios.get).toHaveBeenCalledWith('/api/events/42/standard-emails/7/preview')
        expect(previewModal.value.open).toBe(true)
        expect(previewModal.value.eventId).toBe(42)
        expect(previewModal.value.standardEmailId).toBe(7)
        expect(previewModal.value.to).toBe('klant@example.com')
        expect(previewModal.value.subject).toBe('Onderwerp')
        expect(previewModal.value.body).toBe('<p>Body</p>')
        expect(previewModal.value.trigger).toBe('event_created')
        expect(previewModal.value.editable).toBe(false)
        expect(previewModalKey.value).toBe(keyBefore + 1)
    })

    it('defaults to an editable send when no options are passed', async () => {
        const { previewModal, openPreview } = useStandardEmailPreview()

        await openPreview(1, 2)

        expect(previewModal.value.editable).toBe(true)
        expect(previewModal.value.trigger).toBe(null)
    })
})
