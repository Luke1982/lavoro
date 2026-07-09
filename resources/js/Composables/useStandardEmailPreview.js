import { ref } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'

/**
 * Shared state + fetch for the standard-email preview/send modal.
 * Both the event edit modal (manual send / resend) and the planner
 * (trigger-driven pending queue) drive the same EmailPreviewModal through this.
 *
 * The bumping `previewModalKey` forces EmailPreviewModal to remount on every
 * open, so its local editable fields always start from fresh preview data.
 */
export function useStandardEmailPreview() {
    const page = usePage()
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
        try {
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
        } catch (e) {
            page.props.flash.error = e.response?.data?.message || 'Kon e-mail voorbeeld niet laden'
        }
    }

    async function sendDirect(eventId, standardEmailId, trigger = null) {
        try {
            const { data } = await axios.get(`/api/events/${eventId}/standard-emails/${standardEmailId}/preview`)
            await axios.get('sanctum/csrf-cookie')
            const r = await axios.post(`/api/events/${eventId}/standard-emails/send`, {
                standard_email_id: standardEmailId,
                to: data.to,
                subject: data.subject,
                body: data.body,
                trigger,
            })
            page.props.flash.success = r.data?.message || ('E-mail verzonden aan ' + data.to)
            return true
        } catch (e) {
            page.props.flash.error = e.response?.data?.message || 'Kon e-mail niet verzenden'
            return false
        }
    }

    return { previewModal, previewModalKey, openPreview, sendDirect }
}
