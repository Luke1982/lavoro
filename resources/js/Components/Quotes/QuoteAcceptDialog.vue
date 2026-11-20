<template>
    <ModalDialog :open="open" @update:open="(value) => open = value" max-width-class="sm:max-w-3xl"
        title="Akkoord van de klant vastleggen">
        <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">
            De klant tekent hier, op dit scherm. Vink aan welke opties de klant neemt, en welk alternatief.
        </p>

        <QuoteDocument v-model="form.options" class="mt-4" :lines="lines" :totals="totals" :layout="layout" pickable />
        <p v-if="form.errors.options" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.options }}</p>

        <div class="mt-6 space-y-4">
            <div>
                <span class="text-xs font-bold text-slate-400">Naam van de klant</span>
                <TextInput v-model="form.name" :has-error="Boolean(form.errors.name)" :error-message="form.errors.name" />
            </div>
            <div>
                <span class="text-xs font-bold text-slate-400">Handtekening van de klant</span>
                <SignaturePad ref="pad" v-model="form.signature" class="mt-1" />
                <p v-if="form.errors.signature" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ form.errors.signature }}</p>
            </div>
            <label v-if="termsName" class="flex items-start gap-2 text-sm">
                <input v-model="form.terms_accepted" type="checkbox" class="mt-0.5 rounded border-gray-300 text-lavoro-blue" />
                <span>
                    De klant heeft de <a href="/quote-terms" target="_blank" class="font-semibold text-lavoro-blue underline">algemene voorwaarden</a>
                    ({{ termsName }}) ontvangen en gaat ermee akkoord.
                </span>
            </label>
            <p v-if="form.errors.terms_accepted" class="text-sm text-red-600 dark:text-red-400">{{ form.errors.terms_accepted }}</p>
            <label v-if="isConsumer" class="flex items-start gap-2 text-sm">
                <input v-model="form.starts_within_cooling_off" type="checkbox" class="mt-0.5 rounded border-gray-300 text-lavoro-blue" />
                <span>
                    De klant wil dat het werk binnen de bedenktijd van 14 dagen begint. Wie daarna herroept, betaalt voor
                    wat dan al gedaan is.
                </span>
            </label>
            <label v-if="canCreateServiceOrder" class="flex items-center gap-2 text-sm">
                <SwitchComponent v-model="form.make_service_order" />
                Direct een werkbon maken
            </label>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <button type="button" @click="open = false"
                class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                Annuleren
            </button>
            <button type="button" @click="submit" :disabled="form.processing"
                class="px-4 py-2 text-sm font-medium bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-60">
                Akkoord vastleggen
            </button>
        </div>
    </ModalDialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import SignaturePad from '@/Components/UI/SignaturePad.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import QuoteDocument from '@/Components/Quotes/QuoteDocument.vue'
import { countedOptions } from '@/Components/Quotes/quoteColumns'

/**
 * The customer says yes in front of whoever made the quote, and signs on the
 * screen, ticking off the general terms the way they would on their own page.
 * The werkbon can follow in the same step; then the page goes there.
 */
const props = defineProps({
    quoteId: { type: Number, required: true },
    lines: { type: Array, required: true },
    totals: { type: Object, required: true },
    defaultName: { type: String, default: '' },
    canCreateServiceOrder: { type: Boolean, default: false },
    layout: { type: Object, required: true },
    /** The general terms the customer accepts with it, when the quote goes out with them. */
    termsName: { type: String, default: null },
    /** A private customer gets 14 days to change their mind, and may ask to start sooner. */
    isConsumer: { type: Boolean, default: false },
})

const open = defineModel({ type: Boolean, default: false })
const pad = ref(null)

const form = useForm({
    name: '',
    signature: '',
    options: [],
    terms_accepted: false,
    starts_within_cooling_off: false,
    make_service_order: true,
})

watch(open, (opened) => {
    if (!opened) return
    form.reset()
    form.clearErrors()
    form.name = props.defaultName
    form.options = countedOptions(props.lines)
    form.make_service_order = props.canCreateServiceOrder
}, { immediate: true })

/** The signature as drawn, also when nobody pressed save under it. */
function submit() {
    if (pad.value && !pad.value.isEmpty()) {
        form.signature = pad.value.getDataUrl()
    }
    form.post(`/quotes/${props.quoteId}/accept`, { preserveScroll: true, onSuccess: () => { open.value = false } })
}
</script>
