<template>
    <BoxComponent class="max-w-2xl">
        <SectionHeader :icon="ScaleIcon" title="Algemene voorwaarden" chapter="documents"
            subtitle="Gaan mee met elke offerte, en bij het akkoord vinkt de klant aan ze te hebben ontvangen. Dat moet volgens de wet vóór of bij het tekenen." />

        <SegmentedToggle v-model="kind" :options="KINDS" @update:model-value="saveKind" />

        <div v-if="kind === 'pdf'" class="mt-4">
            <div v-if="settings.terms_name" class="flex items-center justify-between gap-3 rounded-lg ring-1 ring-gray-200 dark:ring-slate-700 px-3 py-2">
                <a href="/quote-terms" target="_blank" class="inline-flex min-w-0 items-center gap-2 text-sm font-medium text-lavoro-blue hover:underline">
                    <FileTextIcon class="size-4 shrink-0" /> <span class="truncate">{{ settings.terms_name }}</span>
                </a>
                <button type="button" @click="removeFile" class="text-sm text-red-600 hover:underline">Weghalen</button>
            </div>
            <form class="mt-3 flex flex-wrap items-center gap-2" @submit.prevent="upload">
                <input type="file" accept="application/pdf" @change="(event) => file.terms = event.target.files[0]"
                    class="block flex-1 text-sm text-gray-900 dark:text-slate-100" />
                <button type="submit" :disabled="!file.terms || file.processing" :class="PRIMARY">
                    <UploadIcon class="size-4" /> {{ settings.terms_name ? 'Vervangen' : 'Uploaden' }}
                </button>
            </form>
            <p v-if="file.errors.terms" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ file.errors.terms }}</p>
        </div>

        <div v-else-if="kind === 'text'" class="mt-4">
            <TipTapEditor v-model="form.terms_text" :has-error="Boolean(form.errors.terms_text)" />
            <p v-if="form.errors.terms_text" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.terms_text }}</p>
            <div class="mt-3 flex items-center justify-between gap-3">
                <a v-if="settings.terms_text" href="/quote-terms" target="_blank" class="text-sm font-medium text-lavoro-blue hover:underline">
                    Bekijken zoals de klant ze krijgt
                </a>
                <span v-else />
                <button type="button" @click="saveText" :disabled="form.terms_text === settings.terms_text || form.processing" :class="PRIMARY">
                    Opslaan
                </button>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-slate-400">De klant krijgt deze tekst als PDF bij de offerte, om te bewaren.</p>
        </div>

        <div v-else-if="kind === 'link'" class="mt-4">
            <TextInput v-model="form.terms_url" type="url" placeholder="https://www.uwbedrijf.nl/algemene-voorwaarden"
                @change="saveUrl" :has-error="Boolean(form.errors.terms_url)" :error-message="form.errors.terms_url" />
            <p class="mt-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 px-3 py-2 text-xs text-amber-900 dark:text-amber-200">
                Een link staat in de mail en op de offerte, maar de klant krijgt de voorwaarden zo niet in handen, en een webpagina kan
                later veranderen. Voor zakelijke klanten volstaat dat meestal; een particulier moet ze kunnen bewaren, kies voor hen
                liever PDF of tekst.
            </p>
        </div>

        <p v-else class="mt-4 text-sm text-gray-500 dark:text-slate-400">Offertes gaan zonder algemene voorwaarden de deur uit.</p>

        <p v-if="kind" class="mt-3 text-xs text-gray-500 dark:text-slate-400">
            Wat je hier verandert, geldt voor wat je vanaf nu verstuurt. Een klant die eerder een offerte kreeg, houdt de voorwaarden van toen.
        </p>
    </BoxComponent>
</template>

<script setup>
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { FileTextIcon, ScaleIcon, UploadIcon } from '@lucide/vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import SegmentedToggle from '@/Components/UI/SegmentedToggle.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import TipTapEditor from '@/Components/UI/TipTapEditor.vue'

/**
 * How the general terms come with a quote, one way at a time: a PDF you
 * upload, a text you write here, or a link to the page where you keep them.
 * What each way holds is kept when you pick another, so going back finds it.
 */
const props = defineProps({
    settings: { type: Object, required: true },
})

const KINDS = [
    { value: '', label: 'Geen' },
    { value: 'pdf', label: 'PDF' },
    { value: 'text', label: 'Tekst' },
    { value: 'link', label: 'Link' },
]

const PRIMARY = 'inline-flex items-center gap-1.5 rounded-lg bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50'

const kind = ref(props.settings.terms_kind ?? '')
watch(() => props.settings.terms_kind, (value) => { kind.value = value ?? '' })

const form = useForm({ terms_text: props.settings.terms_text, terms_url: props.settings.terms_url })
const file = useForm({ terms: null })

const save = (changes) => form.transform(() => changes).patch('/quote-settings', { preserveScroll: true, preserveState: true })

function saveKind(value) {
    save({ terms_kind: value || null })
}

function saveText() {
    save({ terms_text: form.terms_text })
}

function saveUrl() {
    if (form.terms_url !== props.settings.terms_url) save({ terms_url: form.terms_url || null })
}

function upload() {
    file.post('/quote-settings/terms', { preserveScroll: true, forceFormData: true, onSuccess: () => file.reset() })
}

function removeFile() {
    if (!confirm('De PDF met algemene voorwaarden weghalen? Offertes gaan dan zonder voorwaarden de deur uit.')) return
    router.delete('/quote-settings/terms', { preserveScroll: true })
}
</script>
