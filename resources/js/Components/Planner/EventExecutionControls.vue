<template>
    <div v-if="isMine" class="flex items-center gap-1" @click.stop @pointerdown.stop>
        <template v-if="status === 'Gepland'">
            <button type="button" v-tooltip="'Starten'"
                class="p-1 rounded text-green-600 hover:bg-green-50 disabled:opacity-40"
                :disabled="busy" @click="start">
                <Play class="size-4" />
            </button>
            <button type="button" v-tooltip="'Annuleren'"
                class="p-1 rounded text-red-600 hover:bg-red-50 disabled:opacity-40"
                :disabled="busy" @click="cancel">
                <X class="size-4" />
            </button>
        </template>

        <template v-else-if="status === 'Gaande'">
            <button type="button" v-tooltip="'Afronden'"
                class="p-1 rounded text-blue-600 hover:bg-blue-50 disabled:opacity-40"
                :disabled="busy" @click="openStop">
                <Square class="size-4" />
            </button>
            <button type="button" v-tooltip="'Annuleren'"
                class="p-1 rounded text-red-600 hover:bg-red-50 disabled:opacity-40"
                :disabled="busy" @click="cancel">
                <X class="size-4" />
            </button>
        </template>

        <template v-else-if="status === 'Afgerond'">
            <button type="button" v-tooltip="'Tijden/handtekening aanpassen'"
                class="p-1 rounded text-gray-600 hover:bg-gray-100 disabled:opacity-40"
                :disabled="busy" @click="openEdit">
                <SaveAll class="size-4" />
            </button>
        </template>

        <template v-else-if="status === 'Geannuleerd'">
            <Ban class="size-4 text-gray-400" v-tooltip="'Geannuleerd'" />
        </template>

        <ExecutionModal v-if="modalOpen" :open="modalOpen" :mode="modalMode"
            :initial-signature="editSignature" :busy="busy"
            @update:open="modalOpen = $event" @confirm="onModalConfirm">
            <div v-if="modalMode === 'edit'" class="grid grid-cols-2 gap-3">
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
import { Play, Square, X, SaveAll, Ban } from '@lucide/vue'
import { nlTime, formatLocalDateAsISO, localToUtcDatetime, hasPermission } from '@/Utilities/Utilities'

const props = defineProps({
    event: { type: Object, required: true },
})

const emit = defineEmits(['changed'])

const page = usePage()
const authUserId = computed(() => page.props.auth?.user?.id ?? null)

const busy = ref(false)
const modalOpen = ref(false)
const modalMode = ref('stop')
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

function start() {
    postTransition({ status: 'Gaande' })
}

function cancel() {
    if (!window.confirm('Weet je zeker dat je deze afspraak wilt annuleren?')) return
    postTransition({ status: 'Geannuleerd' })
}

function openStop() {
    modalMode.value = 'stop'
    editSignature.value = ''
    modalOpen.value = true
}

async function openEdit() {
    busy.value = true
    try {
        const { data } = await axios.get(`/api/events/${props.event.id}/execution`)
        editSignature.value = data.signature_base64 ?? ''
        editStart.value = data.actual_start ? nlTime(data.actual_start) : ''
        editEnd.value = data.actual_end ? nlTime(data.actual_end) : ''
        modalMode.value = 'edit'
        modalOpen.value = true
    } finally {
        busy.value = false
    }
}

async function onModalConfirm(signature) {
    if (modalMode.value === 'stop') {
        await postTransition({ status: 'Afgerond', signature_base64: signature })
        modalOpen.value = false
        return
    }

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
