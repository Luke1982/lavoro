import { ref } from 'vue'
import axios from 'axios'

export function useEventFeedback() {
    const open = ref(false)
    const activeEvent = ref(null)
    const remarks = ref([])
    const images = ref([])
    const changed = ref(0)

    const openFeedback = async (event) => {
        activeEvent.value = event
        remarks.value = []
        images.value = []
        const { data } = await axios.get(`/api/events/${event.id}/feedback`)
        remarks.value = data.remarks ?? []
        images.value = data.images ?? []
        open.value = true
    }

    const onRemarkCreated = (remark) => {
        remarks.value = [remark, ...remarks.value]
        changed.value++
    }

    const onRemarkDeleted = (id) => {
        remarks.value = remarks.value.filter((remark) => remark.id !== id)
        changed.value++
    }

    const onImagesUploaded = (uploaded) => {
        const list = Array.isArray(uploaded) ? uploaded : [uploaded]
        images.value = [...images.value, ...list]
        changed.value++
    }

    const onImageDeleted = (id) => {
        images.value = images.value.filter((image) => image.id !== id)
        changed.value++
    }

    return {
        open,
        activeEvent,
        remarks,
        images,
        changed,
        openFeedback,
        onRemarkCreated,
        onRemarkDeleted,
        onImagesUploaded,
        onImageDeleted,
    }
}
