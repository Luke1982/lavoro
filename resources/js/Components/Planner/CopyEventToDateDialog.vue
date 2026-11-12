<template>
    <ModalDialog :open="open" title="Afspraak kopiëren naar datum" @update:open="$emit('update:open', $event)">
        <form id="copy-event-to-date-form" class="space-y-4" @submit.prevent="submit">
            <p v-if="event" class="text-sm text-gray-600 dark:text-slate-300">
                De kopie van #{{ event.id }} {{ event.name || event.title }} ({{ nlDate(event.start) }}) krijgt
                dezelfde tijd, monteurs en werkbon.
            </p>
            <TextInput id="copy-event-to-date" v-model="date" type="date" label="Datum" :has-error="!!error"
                :error-message="error" error-id="copy-event-to-date-error" />
        </form>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="$emit('update:open', false)"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="submit" form="copy-event-to-date-form" :disabled="saving"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed">
                    Kopiëren
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import axios from 'axios'
import dayjs from '@/Utilities/dayjs'
import { nlDate } from '@/Utilities/Utilities'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import TextInput from '@/Components/UI/TextInput.vue'

const props = defineProps({
    open: { type: Boolean, required: true },
    event: { type: Object, default: null },
})

const emit = defineEmits(['update:open', 'copied'])

const date = ref('')
const error = ref('')
const saving = ref(false)

watch(() => props.open, (open) => {
    if (!open) return
    date.value = dayjs(props.event.start).add(1, 'day').format('YYYY-MM-DD')
    error.value = ''
})

async function submit() {
    if (saving.value) return
    saving.value = true
    error.value = ''
    try {
        await axios.get('/sanctum/csrf-cookie')
        const r = await axios.post(`/api/events/${props.event.id}/copy`, { date: date.value })
        if (r.status !== 201) throw new Error('bad response')
        emit('copied', r.data)
        emit('update:open', false)
    } catch (e) {
        error.value = e.response?.data?.errors?.date?.[0] || e.response?.data?.message || 'Kon afspraak niet kopiëren'
    } finally {
        saving.value = false
    }
}
</script>
