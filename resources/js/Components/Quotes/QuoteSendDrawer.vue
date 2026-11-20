<template>
    <DrawerComponent v-model="open" title="Offerte versturen"
        subtitle="De klant krijgt een knop naar de offerte, waar hij akkoord geeft of een wijziging vraagt.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <DrawerField label="Aan">
                <TextInput v-model="form.to" type="email" :has-error="Boolean(form.errors.to)" :error-message="form.errors.to" />
            </DrawerField>
            <DrawerField label="Onderwerp">
                <TextInput v-model="form.subject" :has-error="Boolean(form.errors.subject)" :error-message="form.errors.subject" />
            </DrawerField>
            <DrawerField label="Tekst" tall>
                <TipTapEditor v-model="form.body" :has-error="Boolean(form.errors.body)" />
                <p v-if="form.errors.body" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.body }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                    Onder de tekst komt de knop naar de offerte. De link werkt zolang de offerte geldig is, en de link die de
                    klant eerder kreeg werkt daarna niet meer.
                </p>
            </DrawerField>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="open = false"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submit" :disabled="form.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60">
                    Versturen
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import DrawerField from '@/Components/UI/DrawerField.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import TipTapEditor from '@/Components/UI/TipTapEditor.vue'

/** Sending a quote: recipient, subject and text start from what the quote suggests, and are the colleague's to change. */
const props = defineProps({
    quoteId: { type: Number, required: true },
    defaults: { type: Object, required: true },
})

const open = defineModel({ type: Boolean, default: false })

const form = useForm({ to: '', subject: '', body: '' })

watch(open, (opened) => {
    if (!opened) return
    form.clearErrors()
    Object.assign(form, { to: props.defaults.to ?? '', subject: props.defaults.subject, body: props.defaults.body })
}, { immediate: true })

function submit() {
    form.post(`/quotes/${props.quoteId}/send`, { preserveScroll: true, onSuccess: () => { open.value = false } })
}
</script>
