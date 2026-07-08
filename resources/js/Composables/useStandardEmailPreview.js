import { ref } from 'vue'
import axios from 'axios'

/**
 * Shared state + fetch for the standard-email preview/send modal.
 * Both the event edit modal (manual send / resend) and the planner
 * (trigger-driven pending queue) drive the same EmailPreviewModal through this.
 *
 * The bumping `previewModalKey` forces EmailPreviewModal to remount on every
 * open, so its local editable fields always start from fresh preview data.
 */
export function useStandardEmailPreview() {
    const previewModal = ref({
        open: false,
        eventId: null,
        standardEmailId: null,
        to: '',
        subject: '',
        body: '',
        trigger: null,
        editable: true,
    })
    const previewModalKey = ref(0)

    async function openPreview(eventId, standardEmailId, { trigger = null, editable = true } = {}) {
        const { data } = await axios.get(`/api/events/${eventId}/standard-emails/${standardEmailId}/preview`)
        previewModal.value = {
            open: true,
            eventId,
            standardEmailId,
            to: data.to || '',
            subject: data.subject,
            body: data.body,
            trigger,
            editable,
        }
        previewModalKey.value += 1
    }

    return { previewModal, previewModalKey, openPreview }
}
