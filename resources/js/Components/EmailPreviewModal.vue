<template>
    <ModalDialog :open="open" @update:open="$emit('update:open', $event)" title="E-mail versturen" maxWidthClass="sm:max-w-2xl">
        <div class="space-y-4">
            <TextInput v-model="localTo" label="Aan" type="email" :disabled="!editable" />
            <TextInput v-model="localSubject" label="Onderwerp" type="text" :disabled="!editable" />
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Bericht</label>
                <TipTapEditor v-if="editable" v-model="localBody" />
                <div v-else class="prose prose-sm max-w-none dark:prose-invert border rounded-lg px-3 py-2 dark:border-slate-600"
                    v-html="localBody" />
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-3">
                <button type="button" @click="$emit('update:open', false)"
                    class="px-4 py-2 rounded-lg border border-gray-200 dark:border-gray-600 text-sm font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">
                    Annuleren
                </button>
                <button type="button" @click="send" :disabled="sending"
                    class="px-4 py-2 rounded-lg bg-lavoro-blue text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ sending ? 'Versturen...' : 'Versturen' }}
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import axios from 'axios'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import TipTapEditor from '@/Components/UI/TipTapEditor.vue'

const props = defineProps({
    open: { type: Boolean, required: true },
    eventId: { type: [Number, String], default: null },
    standardEmailId: { type: [Number, String], default: null },
    to: { type: String, default: '' },
    subject: { type: String, default: '' },
    body: { type: String, default: '' },
    trigger: { type: String, default: null },
    editable: { type: Boolean, default: true },
})
const emit = defineEmits(['update:open', 'sent'])

const localTo = ref(props.to)
const localSubject = ref(props.subject)
const localBody = ref(props.body)
const sending = ref(false)

watch(() => [props.to, props.subject, props.body], () => {
    localTo.value = props.to
    localSubject.value = props.subject
    localBody.value = props.body
})

async function send() {
    if (sending.value) return
    sending.value = true
    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.post(`/api/events/${props.eventId}/standard-emails/send`, {
            standard_email_id: props.standardEmailId,
            to: localTo.value,
            subject: localSubject.value,
            body: localBody.value,
            trigger: props.trigger,
        })
        emit('sent')
        emit('update:open', false)
    } finally {
        sending.value = false
    }
}
</script>
