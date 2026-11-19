<template>
    <button type="button" :disabled="busy || starting" :title="label" :aria-label="label"
        class="inline-flex items-center gap-1 rounded-lg px-1.5 py-1 text-[11px] font-medium ring-1 disabled:opacity-60"
        :class="recording
            ? 'bg-rose-50 text-rose-700 ring-rose-300 dark:bg-rose-900/30 dark:text-rose-200 dark:ring-rose-700'
            : 'text-slate-600 ring-slate-200 hover:bg-rose-50 hover:text-rose-700 hover:ring-rose-300 dark:text-slate-400 dark:ring-slate-700 dark:hover:bg-rose-900/20 dark:hover:text-rose-300'"
        @click="toggle">
        <StopIcon v-if="recording" class="size-4 animate-pulse text-rose-600" />
        <MicrophoneIcon v-else class="size-4 text-rose-600 dark:text-rose-400" />
        {{ busy ? 'Omzetten…' : recording ? clock : unsent ? 'Opnieuw omzetten' : 'Inspreken' }}
    </button>
</template>

<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import axios from 'axios'
import { MicrophoneIcon } from '@heroicons/vue/24/outline'
import { StopIcon } from '@heroicons/vue/24/solid'

/**
 * Speaking to the assistant instead of typing: one press starts recording, the
 * next stops it, and the recording is written out by the server. A voice memo
 * made earlier goes the same way through transcribe(). The words come back as
 * text for the question box, never straight to the assistant, so whoever spoke
 * reads what was understood first.
 *
 * The panel keeps this button mounted on its other tabs, so a recording goes on
 * while someone looks at an earlier conversation. A recording that could not be
 * sent, for a dropped connection or a busy supplier, is kept: the next press
 * sends it again instead of asking to speak five minutes over.
 */
const emit = defineEmits(['text', 'error'])

const MAX_SECONDS = 300

const recording = ref(false)
const starting = ref(false)
const busy = ref(false)
const seconds = ref(0)

let recorder = null
let stream = null
let timer = null
let unmounted = false

/** A recording that failed to go through for a reason that may pass. */
const unsent = ref(null)

const label = computed(() => {
    if (recording.value) return 'Stoppen en omzetten in tekst'

    return unsent.value ? 'De opname opnieuw omzetten in tekst' : 'Inspreken: vertel wat je wilt'
})

const clock = computed(() => `${Math.floor(seconds.value / 60)}:${String(seconds.value % 60).padStart(2, '0')}`)

function toggle() {
    if (recording.value) return stop()
    if (unsent.value) return transcribe(unsent.value.audio, unsent.value.name)

    start()
}

async function start() {
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
        emit('error', 'Deze browser kan niet opnemen. Kies een opname met Bestand.')
        return
    }

    starting.value = true
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    } catch {
        emit('error', 'Lavoro mag de microfoon niet gebruiken. Sta dat toe in je browser, of kies een opname met Bestand.')
        return
    } finally {
        starting.value = false
    }

    if (unmounted) {
        release()
        return
    }

    const chunks = []
    const thisRecorder = new MediaRecorder(stream)
    thisRecorder.ondataavailable = event => event.data.size && chunks.push(event.data)
    thisRecorder.onstop = () => {
        const type = thisRecorder.mimeType || 'audio/webm'
        release()
        transcribe(new Blob(chunks, { type }), 'opname.' + (type.includes('mp4') ? 'm4a' : 'webm'))
    }
    recorder = thisRecorder
    recorder.start()

    seconds.value = 0
    recording.value = true
    timer = setInterval(() => {
        seconds.value++
        if (seconds.value >= MAX_SECONDS) stop()
    }, 1000)
}

function stop() {
    clearInterval(timer)
    recording.value = false
    if (recorder?.state === 'recording') recorder.stop()
}

function release() {
    stream?.getTracks().forEach(track => track.stop())
    stream = null
}

/** Writes out a recording, made here or picked as a file. */
async function transcribe(audio, name) {
    busy.value = true
    const form = new FormData()
    form.append('audio', audio, name)

    try {
        const { data } = await axios.post('/assistant/transcribe', form)
        unsent.value = null
        emit('text', data.text)
    } catch (error) {
        const status = error.response?.status
        const passing = error.response?.data?.retry || !status || status === 429 || status >= 500
        unsent.value = passing ? { audio, name } : null
        const errors = error.response?.data?.errors
        emit('error', errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Omzetten in tekst lukte niet. Probeer het nog eens.'))
    } finally {
        busy.value = false
    }
}

onBeforeUnmount(() => {
    unmounted = true
    clearInterval(timer)
    if (recorder?.state === 'recording') {
        recorder.onstop = null
        recorder.stop()
    }
    release()
})

defineExpose({ transcribe })
</script>
