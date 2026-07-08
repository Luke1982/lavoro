<template>
    <div class="p-6 max-w-4xl">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Standaard e-mails</h1>
            <button type="button" @click="openCreate"
                class="px-4 py-2 rounded-md bg-lavoro-blue text-sm font-semibold text-white hover:bg-blue-700">
                Nieuwe standaard e-mail
            </button>
        </div>

        <ul class="divide-y divide-gray-100 dark:divide-slate-700 rounded-lg border border-gray-100 dark:border-slate-700">
            <li v-for="email in standardEmails" :key="email.id" class="px-4 py-3 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ email.name }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ email.subject }}</p>
                    <div class="flex gap-1 mt-1 flex-wrap">
                        <span v-for="trigger in email.triggers" :key="trigger.id"
                            class="inline-flex items-center rounded-full bg-lavoro-green/20 text-gray-800 dark:text-gray-100 px-2 py-0.5 text-[0.65rem] font-medium">
                            {{ triggerLabel(trigger.trigger) }} · {{ triggerTypeLabel(trigger.trigger_type) }}
                        </span>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <button type="button" @click="openEdit(email)" class="text-sm text-lavoro-blue hover:underline">
                        Bewerken
                    </button>
                    <button type="button" @click="remove(email)" class="text-sm text-red-600 hover:underline">
                        Verwijderen
                    </button>
                </div>
            </li>
            <li v-if="standardEmails.length === 0" class="px-4 py-3 text-sm text-gray-400">
                Nog geen standaard e-mails.
            </li>
        </ul>

        <ModalDialog :open="modalOpen" @update:open="modalOpen = $event"
            :title="editingId ? 'Standaard e-mail bewerken' : 'Nieuwe standaard e-mail'" maxWidthClass="sm:max-w-2xl">
            <form @submit.prevent="save" class="space-y-4">
                <TextInput v-model="form.name" label="Naam" type="text" :hasError="Boolean(form.errors.name)"
                    :errorMessage="form.errors.name" />
                <TextInput v-model="form.subject" label="Onderwerp" type="text" :hasError="Boolean(form.errors.subject)"
                    :errorMessage="form.errors.subject" />

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Inhoud</label>
                    <TipTapEditor v-model="form.body" :placeholders="placeholders" :hasError="Boolean(form.errors.body)" />
                    <p v-if="form.errors.body" class="mt-1 text-xs text-red-600">{{ form.errors.body }}</p>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Automatisch versturen bij</label>
                        <button type="button" @click="addTrigger" class="text-sm text-lavoro-blue hover:underline">
                            Trigger toevoegen
                        </button>
                    </div>
                    <div v-for="(trigger, index) in form.triggers" :key="index" class="flex gap-2 mb-2 items-start">
                        <ComboBox v-model="trigger.trigger" :options="eventTriggers"
                            :initial-id="trigger.trigger" class="flex-1" placeholder="Moment..." />
                        <ComboBox v-model="trigger.trigger_type" :options="triggerTypes"
                            :initial-id="trigger.trigger_type" class="flex-1" placeholder="Verzendwijze..." />
                        <button type="button" @click="form.triggers.splice(index, 1)"
                            class="p-2 text-gray-400 hover:text-red-600">
                            <XMarkIcon class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Standaard bijlagen</label>
                    <ComboBox v-model="form.standard_attachment_ids" :options="standardAttachments" multiple
                        :initial-ids="form.standard_attachment_ids" placeholder="Kies bijlagen..." />
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="modalOpen = false"
                        class="px-4 py-2 rounded-lg border border-gray-200 dark:border-gray-600 text-sm font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">
                        Annuleren
                    </button>
                    <button type="submit" :disabled="form.processing"
                        class="px-4 py-2 rounded-lg bg-lavoro-blue text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
                        Opslaan
                    </button>
                </div>
            </form>
        </ModalDialog>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TipTapEditor from '@/Components/UI/TipTapEditor.vue'

const props = defineProps({
    standardEmails: { type: Array, required: true },
    standardAttachments: { type: Array, required: true },
    eventTriggers: { type: Array, required: true },
    triggerTypes: { type: Array, required: true },
    placeholders: { type: Array, required: true },
})

const modalOpen = ref(false)
const editingId = ref(null)

const form = useForm({
    name: '',
    subject: '',
    body: '',
    triggers: [],
    standard_attachment_ids: [],
})

function triggerLabel(name) {
    return props.eventTriggers.find((t) => t.id === name)?.name || name
}

function triggerTypeLabel(name) {
    return props.triggerTypes.find((t) => t.id === name)?.name || name
}

function addTrigger() {
    form.triggers.push({ trigger: props.eventTriggers[0]?.id, trigger_type: props.triggerTypes[0]?.id })
}

function openCreate() {
    editingId.value = null
    form.reset()
    form.clearErrors()
    modalOpen.value = true
}

function openEdit(email) {
    editingId.value = email.id
    form.clearErrors()
    form.name = email.name
    form.subject = email.subject
    form.body = email.body
    form.triggers = email.triggers.map((t) => ({ trigger: t.trigger, trigger_type: t.trigger_type }))
    form.standard_attachment_ids = email.standard_attachments.map((a) => a.id)
    modalOpen.value = true
}

function save() {
    if (editingId.value) {
        form.put(`/standard-emails/${editingId.value}`, {
            preserveScroll: true,
            onSuccess: () => { modalOpen.value = false },
        })
    } else {
        form.post('/standard-emails', {
            preserveScroll: true,
            onSuccess: () => { modalOpen.value = false },
        })
    }
}

function remove(email) {
    if (!confirm(`Standaard e-mail "${email.name}" verwijderen?`)) return
    router.delete(`/standard-emails/${email.id}`, { preserveScroll: true })
}
</script>
