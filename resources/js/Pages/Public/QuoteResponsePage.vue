<template>
    <div class="min-h-screen bg-slate-100 dark:bg-slate-800 py-8 px-4">
        <div class="mx-auto w-full max-w-3xl">
            <div v-if="preview_of"
                class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-amber-50 dark:bg-amber-900/20 px-4 py-3 text-sm text-amber-900 dark:text-amber-200 ring-1 ring-amber-200 dark:ring-amber-800">
                <span><strong>Voorbeeld.</strong> {{ preview_note ?? 'Zo ziet de klant deze offerte.' }} Reageren kan hier niet.</span>
                <a :href="preview_of" class="font-semibold underline">Terug naar de offerte</a>
            </div>
            <div class="rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-700 overflow-hidden">
                <header class="flex items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-800 px-6 py-5">
                    <img v-if="company?.logo_url" :src="company.logo_url" :alt="company.name" class="h-9 w-auto" />
                    <span v-else class="text-lg font-bold text-slate-800 dark:text-slate-200">{{ seller.name || company?.name || 'Offerte' }}</span>
                    <a :href="pdfUrl" target="_blank"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-300 dark:border-slate-600 px-3 py-1.5 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">
                        <FileDownIcon class="size-4" /> Download als PDF
                    </a>
                </header>

                <div class="px-6 py-6">
                    <QuoteCustomerDocument v-model="accept.options" :quote="quote" :lines="lines" :totals="totals" :layout="layout"
                        :terms="terms" :withdrawal="withdrawal" :provisional_explanation="provisional_explanation" :attachments="attachments"
                        :pickable="open">
                        <template #notice>
                            <p v-if="flash.error" class="mt-4 rounded-xl bg-red-50 dark:bg-red-900/20 px-4 py-3 text-sm text-red-800 dark:text-red-200">
                                {{ flash.error }}
                            </p>
                        </template>
                    </QuoteCustomerDocument>

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
                            <div v-if="terms">
                                <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                                    <input v-model="accept.terms_accepted" type="checkbox" class="mt-0.5 rounded border-slate-300 text-green-600" />
                                    <span>
                                        Ik heb de <a :href="termsUrl" target="_blank" class="font-semibold underline">algemene voorwaarden</a>
                                        ontvangen en ga ermee akkoord.
                                    </span>
                                </label>
                                <p v-if="accept.errors.terms_accepted" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ accept.errors.terms_accepted }}</p>
                            </div>
                            <label v-if="withdrawal" class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                                <input v-model="accept.starts_within_cooling_off" type="checkbox" class="mt-0.5 rounded border-slate-300 text-green-600" />
                                <span>
                                    Ik wil dat de werkzaamheden binnen de bedenktijd beginnen. Als ik daarna herroep, betaal ik voor wat
                                    dan al gedaan is.
                                </span>
                            </label>
                            <button type="submit" :disabled="accept.processing || Boolean(preview_of)"
                                class="w-full rounded-lg bg-green-600 px-4 py-3 text-sm font-bold text-white hover:bg-green-700 disabled:opacity-60">
                                Akkoord met deze offerte
                            </button>
                        </form>

                        <form v-else-if="answer === 'changes'" class="mt-4 space-y-4" @submit.prevent="send(changes, 'wijziging')">
                            <div>
                                <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Uw naam</label>
                                <TextInput v-model="changes.name" autocomplete="name" :has-error="Boolean(changes.errors.name)"
                                    :error-message="changes.errors.name" />
                            </div>
                            <div>
                                <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Wat zou u anders willen?</label>
                                <textarea v-model="changes.message" rows="5" :class="TEXTAREA" />
                                <p v-if="changes.errors.message" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ changes.errors.message }}</p>
                            </div>
                            <button type="submit" :disabled="changes.processing || Boolean(preview_of)"
                                class="w-full rounded-lg bg-lavoro-blue px-4 py-3 text-sm font-bold text-white hover:opacity-90 disabled:opacity-60">
                                Wijziging vragen
                            </button>
                        </form>

                        <form v-else class="mt-4 space-y-4" @submit.prevent="send(decline, 'afwijzen')">
                            <div>
                                <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Uw naam</label>
                                <TextInput v-model="decline.name" autocomplete="name" :has-error="Boolean(decline.errors.name)"
                                    :error-message="decline.errors.name" />
                            </div>
                            <div v-if="lost_reasons.length">
                                <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Waarom niet? (mag u overslaan)</span>
                                <div class="mt-1 space-y-1">
                                    <label v-for="reason in lost_reasons" :key="reason.id" class="flex items-center gap-2 text-sm">
                                        <input type="radio" name="lost_reason" :checked="decline.lost_reason_id === reason.id"
                                            @click="decline.lost_reason_id = decline.lost_reason_id === reason.id ? null : reason.id" />
                                        {{ reason.name }}
                                    </label>
                                </div>
                            </div>
                            <div>
                                <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Wilt u nog iets kwijt? (mag u overslaan)</label>
                                <textarea v-model="decline.message" rows="3" :class="TEXTAREA" />
                            </div>
                            <button type="submit" :disabled="decline.processing || Boolean(preview_of)"
                                class="w-full rounded-lg bg-slate-700 px-4 py-3 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-60">
                                Offerte afwijzen
                            </button>
                        </form>

                        <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                            U kunt één keer reageren. Daarna sturen wij u, als het nodig is, een nieuwe offerte.
                        </p>
                    </template>
                </div>

                <footer v-if="sellerLine" class="border-t border-slate-100 dark:border-slate-800 px-6 py-4 text-xs text-slate-500 dark:text-slate-400">
                    {{ sellerLine }}
                </footer>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { FileDownIcon } from '@lucide/vue'
import EmptyLayout from '@/Layouts/EmptyLayout.vue'
import QuoteCustomerDocument from '@/Components/Quotes/QuoteCustomerDocument.vue'
import SegmentedToggle from '@/Components/UI/SegmentedToggle.vue'
import SignaturePad from '@/Components/UI/SignaturePad.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { countedOptions as counted } from '@/Components/Quotes/quoteColumns'

/**
 * Where a customer without an account looks at a quote and answers it, once:
 * yes, a change, or no. The address of this page is the link itself, so the
 * answers, the PDF and the general terms hang off it too. Everything shown is
 * the version the link went out with.
 */
const props = defineProps({
    quote: { type: Object, required: true },
    lines: { type: Array, required: true },
    totals: { type: Object, required: true },
    layout: { type: Object, required: true },
    /** Who makes the quote: name, address, KvK, VAT number, IBAN. */
    seller: { type: Object, default: () => ({}) },
    /** The general terms it is offered under: { kind, name, url? }; a file comes through the link. */
    terms: { type: Object, default: null },
    /** For a private customer: the 14 days to change their mind, and the model form. */
    withdrawal: { type: Object, default: null },
    lost_reasons: { type: Array, default: () => [] },
    open: { type: Boolean, default: false },
    expires_on: { type: String, default: null },
    /** Set when a colleague opens the page as a preview: where to go back to. Nothing can be sent from it. */
    preview_of: { type: String, default: null },
    /** What the preview banner says, for an earlier version: when it went out and to whom. */
    preview_note: { type: String, default: null },
    /** What a stelpost is, as the PDF says it. */
    provisional_explanation: { type: String, default: null },
    /** The names of the files shared with the customer, which came with the mail. */
    attachments: { type: Array, default: () => [] },
    /** Where a preview finds its PDF; the customer's own page finds it under the link. */
    pdf_url: { type: String, default: null },
})

const ANSWERS = [
    { value: 'accept', label: 'Akkoord geven' },
    { value: 'changes', label: 'Wijziging vragen' },
    { value: 'decline', label: 'Afwijzen' },
]

const TEXTAREA = 'mt-1 block w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 text-sm p-2'

const page = usePage()
const company = computed(() => page.props.company)
const flash = computed(() => page.props.flash ?? {})

const linkUrl = page.url.split('?')[0]
const pdfUrl = computed(() => props.pdf_url ?? `${linkUrl}/pdf`)
/** Kept on the company's own page: that page; otherwise the file, through the link or, in a preview, the settings. */
const termsUrl = computed(() => {
    if (props.terms?.kind === 'link') return props.terms.url
    return props.preview_of ? '/quote-terms' : `${linkUrl}/voorwaarden`
})

const sellerLine = computed(() => [
    props.seller.name,
    [props.seller.address_line1, props.seller.postal_code, props.seller.city].filter(Boolean).join(' '),
    props.seller.coc_number && `KvK ${props.seller.coc_number}`,
    props.seller.vat_number && `Btw ${props.seller.vat_number}`,
    props.seller.iban && `IBAN ${props.seller.iban}`,
].filter(Boolean).join(' · '))

const answer = ref('accept')
const pad = ref(null)
const accept = useForm({ name: '', signature: '', options: counted(props.lines), terms_accepted: false, starts_within_cooling_off: false })
const changes = useForm({ name: '', message: '' })
const decline = useForm({ name: '', lost_reason_id: null, message: '' })

/** The signature as drawn, also when nobody pressed save under it. */
function send(form, path) {
    if (props.preview_of) return
    if (form === accept && pad.value && !pad.value.isEmpty()) {
        accept.signature = pad.value.getDataUrl()
    }
    form.post(`${linkUrl}/${path}`, { preserveScroll: true })
}

defineOptions({ layout: EmptyLayout })
</script>
