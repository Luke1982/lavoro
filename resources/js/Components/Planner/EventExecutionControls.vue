<template>
    <div v-if="isMine" class="flex items-center gap-1" @click.stop @pointerdown.stop>
        <template v-if="status === 'Afgerond'">
            <Check class="size-4 text-green-600" v-tooltip="'Tijden geregistreerd'" />
        </template>

        <template v-else-if="status === 'Geannuleerd'">
            <Ban class="size-4 text-gray-400" v-tooltip="'Geannuleerd'" />
        </template>

        <template v-else>
            <button type="button" v-tooltip="'Tijden en handtekening registreren'"
                class="p-1 rounded text-blue-600 hover:bg-blue-50 disabled:opacity-40"
                :disabled="busy" @click="openModal">
                <Clock class="size-4" />
            </button>
            <button type="button" v-tooltip="'Annuleren'"
                class="p-1 rounded text-red-600 hover:bg-red-50 disabled:opacity-40"
                :disabled="busy" @click="cancel">
                <X class="size-4" />
            </button>
        </template>

        <ExecutionModal v-if="modalOpen" :open="modalOpen"
            :initial-signature="editSignature" :busy="busy"
            @update:open="modalOpen = $event" @confirm="onModalConfirm">
            <div class="grid grid-cols-2 gap-3">
                <TextInput v-model="editStart" type="time" label="Starttijd" />
                <TextInput v-model="editEnd" type="time" label="Eindtijd" />
            </div>
        </ExecutionModal>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import ExecutionModal from '@/Components/Planner/ExecutionModal.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { Clock, Check, X, Ban } from '@lucide/vue'
import { nlTime, formatLocalDateAsISO, localToUtcDatetime, hasPermission } from '@/Utilities/Utilities'

const props = defineProps({
    event: { type: Object, required: true },
})

const emit = defineEmits(['changed'])

const page = usePage()
const authUserId = computed(() => page.props.auth?.user?.id ?? null)

const busy = ref(false)
const modalOpen = ref(false)
const editSignature = ref('')
const editStart = ref('')
const editEnd = ref('')

const myExecution = computed(() =>
    props.event.executing_users?.find(u => u.id === authUserId.value) ?? null
)
const isMine = computed(() => !!myExecution.value && hasPermission('event.execute'))
const status = computed(() => myExecution.value?.completion_status ?? 'Gepland')

async function postTransition(payload) {
    if (busy.value) return
    busy.value = true
    try {
        await axios.get('sanctum/csrf-cookie')
        await axios.post(`/api/events/${props.event.id}/execution/transition`, payload)
        emit('changed')
    } finally {
        busy.value = false
    }
}

function cancel() {
    if (!window.confirm('Weet je zeker dat je deze afspraak wilt annuleren?')) return
    postTransition({ status: 'Geannuleerd' })
}

async function openModal() {
    if (busy.value) return
    busy.value = true
    try {
        const { data } = await axios.get(`/api/events/${props.event.id}/execution`)
        editSignature.value = data.signature_base64 ?? ''
        editStart.value = data.actual_start ? nlTime(data.actual_start) : nlTime(props.event.start)
        editEnd.value = data.actual_end ? nlTime(data.actual_end) : nlTime(props.event.end)
        modalOpen.value = true
    } finally {
        busy.value = false
    }
}

async function onModalConfirm(signature) {
    if (busy.value) return
    busy.value = true
    try {
        const date_iso = formatLocalDateAsISO(props.event.start)
        await axios.get('sanctum/csrf-cookie')
        await axios.patch(`/api/events/${props.event.id}/execution`, {
            actual_start: localToUtcDatetime(date_iso, editStart.value),
            actual_end: localToUtcDatetime(date_iso, editEnd.value),
            signature_base64: signature,
        })
        emit('changed')
        modalOpen.value = false
    } finally {
        busy.value = false
    }
}
</script>
