<template>
    <div class="min-h-screen bg-slate-100 dark:bg-slate-800 py-8 px-4">
        <div class="mx-auto w-full max-w-3xl">
            <div v-if="preview_of"
                class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-amber-50 dark:bg-amber-900/20 px-4 py-3 text-sm text-amber-900 dark:text-amber-200 ring-1 ring-amber-200 dark:ring-amber-800">
                <span><strong>Voorbeeld.</strong> {{ preview_note ?? 'Zo ziet de klant deze offerte.' }} Reageren kan hier niet.</span>
                <a :href="preview_of" class="font-semibold underline">Terug naar de offerte</a>
            </div>
            <div class="rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-700 overflow-hidden">
                <header class="flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 px-6 py-5">
                    <img v-if="company?.logo_url" :src="company.logo_url" :alt="company.name" class="h-9 w-auto" />
                    <span v-else class="text-lg font-bold text-slate-800 dark:text-slate-200">{{ company?.name || 'Offerte' }}</span>
                </header>

                <div class="px-6 py-6">
                    <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ quote.title }}</h1>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Offerte {{ quote.number }}<template v-if="version">, versie {{ version }}</template> voor {{ quote.contact || quote.customer }}, {{ nlDate(quote.quote_date) }}
                        <template v-if="quote.valid_until">· geldig tot en met {{ nlDate(quote.valid_until) }}</template>
                    </p>

                    <p v-if="flash.error" class="mt-4 rounded-xl bg-red-50 dark:bg-red-900/20 px-4 py-3 text-sm text-red-800 dark:text-red-200">
                        {{ flash.error }}
                    </p>

                    <div v-if="quote.intro_text" class="rich-text mt-6 text-sm text-slate-700 dark:text-slate-300" v-html="quote.intro_text" />

                    <QuoteDocument v-model="accept.options" class="mt-6" :lines="lines" :totals="totals" :pickable="open && quote.customer_picks_options" />

                    <p v-if="open && quote.customer_picks_options && hasOptions" class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        Vink de opties aan die u erbij wilt; het totaal rekent mee.
                    </p>

                    <div v-if="quote.closing_text" class="rich-text mt-6 text-sm text-slate-700 dark:text-slate-300" v-html="quote.closing_text" />

                    <div v-if="!open" class="mt-8 rounded-xl bg-amber-50 dark:bg-amber-900/20 px-4 py-4 text-sm text-amber-800 dark:text-amber-200">
                        Op deze offerte kunt u niet meer reageren. Neem gerust contact met ons op.
                    </div>

                    <template v-else>
                        <SegmentedToggle v-model="answer" class="mt-8" :options="ANSWERS" />

                        <form v-if="answer === 'accept'" class="mt-4 space-y-4" @submit.prevent="send(accept, 'akkoord')">
                            <div>
                                <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Uw naam</label>
                                <TextInput v-model="accept.name" autocomplete="name" :has-error="Boolean(accept.errors.name)"
                                    :error-message="accept.errors.name" />
                            </div>
                            <div>
                                <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Uw handtekening</label>
                                <SignaturePad ref="pad" v-model="accept.signature" class="mt-1" />
                                <p v-if="accept.errors.signature" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ accept.errors.signature }}</p>
                            </div>
                            <button type="submit" :disabled="accept.processing || Boolean(preview_of)"
                                class="w-full rounded-lg bg-green-600 px-4 py-3 text-sm font-bold text-white hover:bg-green-700 disabled:opacity-60">
                                Akkoord met deze offerte
                            </button>
                        </form>

                        <form v-else class="mt-4 space-y-4" @submit.prevent="send(changes, 'wijziging')">
                            <div>
                                <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Uw naam</label>
                                <TextInput v-model="changes.name" autocomplete="name" :has-error="Boolean(changes.errors.name)"
                                    :error-message="changes.errors.name" />
                            </div>
                            <div>
                                <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Wat zou u anders willen?</label>
                                <textarea v-model="changes.message" rows="5"
                                    class="mt-1 block w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 text-sm p-2" />
                                <p v-if="changes.errors.message" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ changes.errors.message }}</p>
                            </div>
                            <button type="submit" :disabled="changes.processing || Boolean(preview_of)"
                                class="w-full rounded-lg bg-lavoro-blue px-4 py-3 text-sm font-bold text-white hover:opacity-90 disabled:opacity-60">
                                Wijziging vragen
                            </button>
                        </form>

                        <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                            U kunt één keer reageren. Daarna sturen wij u, als het nodig is, een nieuwe offerte.
                        </p>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import EmptyLayout from '@/Layouts/EmptyLayout.vue'
import QuoteDocument from '@/Components/Quotes/QuoteDocument.vue'
import SegmentedToggle from '@/Components/UI/SegmentedToggle.vue'
import SignaturePad from '@/Components/UI/SignaturePad.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { countedOptions as counted } from '@/Components/Quotes/quoteColumns'
import { nlDate } from '@/Utilities/Utilities'

/**
 * Where a customer without an account looks at a quote and answers it, once.
 * The address of this page is the link itself, so the answers go to it too.
 */
const props = defineProps({
    quote: { type: Object, required: true },
    lines: { type: Array, required: true },
    totals: { type: Object, required: true },
    open: { type: Boolean, default: false },
    expires_on: { type: String, default: null },
    /** Set when a colleague opens the page as a preview: where to go back to. Nothing can be sent from it. */
    preview_of: { type: String, default: null },
    /** What the preview banner says, for an earlier version: when it went out and to whom. */
    preview_note: { type: String, default: null },
    /** The version this page shows, when it shows one that was sent. */
    version: { type: Number, default: null },
})

const ANSWERS = [
    { value: 'accept', label: 'Akkoord geven' },
    { value: 'changes', label: 'Wijziging vragen' },
]

const page = usePage()
const company = computed(() => page.props.company)
const flash = computed(() => page.props.flash ?? {})
const hasOptions = computed(() => props.lines.some(line => line.is_optional))

const answer = ref('accept')
const pad = ref(null)
const accept = useForm({ name: '', signature: '', options: counted(props.lines) })
const changes = useForm({ name: '', message: '' })

/** The signature as drawn, also when nobody pressed save under it. */
function send(form, path) {
    if (props.preview_of) return
    if (form === accept && pad.value && !pad.value.isEmpty()) {
        accept.signature = pad.value.getDataUrl()
    }
    form.post(page.url.split('?')[0] + '/' + path, { preserveScroll: true })
}

defineOptions({ layout: EmptyLayout })
</script>
