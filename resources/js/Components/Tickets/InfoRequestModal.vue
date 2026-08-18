<template>
    <ModalDialog :open="open" @update:open="$emit('update:open', $event)"
        title="Aanvullende informatie opvragen bij de klant" maxWidthClass="sm:max-w-2xl">
        <div v-if="loading" class="py-10 text-center text-sm text-gray-500 dark:text-slate-400">
            Bezig met laden…
        </div>

        <div v-else class="space-y-4">
            <TextInput v-model="form.to" label="Aan" type="email" :hasError="!!errors.to"
                :errorMessage="errors.to || ''" />

            <TextInput v-model="form.subject" label="Onderwerp" type="text" :hasError="!!errors.subject"
                :errorMessage="errors.subject || ''" />

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Wij vragen de klant om
                </label>
                <div class="flex flex-wrap gap-2">
                    <button v-for="option in options" :key="option.key" type="button" @click="toggle(option.key)"
                        :aria-pressed="isSelected(option.key)"
                        :class="['inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors cursor-pointer',
                            isSelected(option.key)
                                ? 'border-lavoro-blue bg-lavoro-blue/10 text-lavoro-blue'
                                : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800']">
                        <CheckIcon v-if="isSelected(option.key)" class="size-4" />
                        {{ option.label }}
                    </button>
                </div>
                <p v-if="errors.requested" class="mt-1 text-sm text-red-600">{{ errors.requested }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Bericht</label>
                <TipTapEditor v-model="form.body" :hasError="!!errors.body" />
                <p v-if="errors.body" class="mt-1 text-sm text-red-600">{{ errors.body }}</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-slate-500">
                    De knop naar de aanleverpagina wordt onder dit bericht toegevoegd.
                </p>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-3">
                <button type="button" @click="$emit('update:open', false)"
                    class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                    Annuleren
                </button>
                <button type="button" @click="send" :disabled="sending || loading || !form.requested.length"
                    class="rounded-lg bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ sending ? 'Versturen…' : 'Versturen' }}
                </button>
            </div>
        </template>
    </ModalDialog>
</template>

<script setup>
import { reactive, ref, watch } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import { CheckIcon } from '@heroicons/vue/24/outline'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import TipTapEditor from '@/Components/UI/TipTapEditor.vue'
import { syncRequestedList } from '@/Components/Tickets/infoRequestBody'

const props = defineProps({
    open: { type: Boolean, required: true },
    ticketId: { type: [Number, String], required: true },
})
const emit = defineEmits(['update:open', 'sent'])

const page = usePage()

const options = ref([])
const loading = ref(false)
const sending = ref(false)
const errors = reactive({})
const form = reactive({ to: '', subject: '', body: '', requested: [] })

const isSelected = (key) => form.requested.includes(key)

const labelsFor = (keys) => options.value.filter((option) => keys.includes(option.key)).map((o) => o.label)

/**
 * Aanklikken zet aan, nog eens aanklikken zet uit. De volgorde in de lijst is die
 * van de knoppen en niet die van het klikken, zodat dezelfde keuze altijd dezelfde
 * brief oplevert.
 */
function toggle(key) {
    const selected = new Set(form.requested)

    if (selected.has(key)) {
        selected.delete(key)
    } else {
        selected.add(key)
    }

    form.requested = options.value.map((option) => option.key).filter((k) => selected.has(k))
    form.body = syncRequestedList(form.body, labelsFor(form.requested), options.value.map((o) => o.label))
}

async function load() {
    loading.value = true
    Object.keys(errors).forEach((key) => delete errors[key])

    try {
        const { data } = await axios.get(`/api/tickets/${props.ticketId}/info-request`)

        options.value = data.options
        form.to = data.to || ''
        form.subject = data.subject
        form.requested = data.requested
        form.body = syncRequestedList(data.body, labelsFor(data.requested), data.options.map((o) => o.label))
    } catch {
        page.props.flash.error = 'Kon de aanvraag niet klaarzetten'
        emit('update:open', false)
    } finally {
        loading.value = false
    }
}

async function send() {
    if (sending.value) return

    sending.value = true
    Object.keys(errors).forEach((key) => delete errors[key])

    try {
        await axios.get('/sanctum/csrf-cookie')
        const { data } = await axios.post(`/api/tickets/${props.ticketId}/info-request`, { ...form })

        page.props.flash.success = data.message
        emit('sent')
        emit('update:open', false)
    } catch (error) {
        const reported = error.response?.data?.errors

        if (reported) {
            Object.entries(reported).forEach(([field, messages]) => {
                errors[field.split('.')[0]] = messages[0]
            })
        } else {
            page.props.flash.error = error.response?.data?.message || 'Kon de aanvraag niet versturen'
        }
    } finally {
        sending.value = false
    }
}

watch(() => props.open, (opened) => {
    if (opened) load()
}, { immediate: true })
</script>
