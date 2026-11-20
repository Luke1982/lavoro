<template>
    <BreadcrumbComponent :items="[{ label: 'Offertes', href: '/quotes' }, { label: quote.number }]" />

    <div class="mt-6 mb-4 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold truncate">{{ quote.title }}</h1>
                <span class="text-sm" :class="quoteStatusClass(quote.status)">{{ quote.status }}</span>
            </div>
            <p class="text-sm text-gray-500 dark:text-slate-400">
                {{ quote.number }} ·
                <Link :href="`/customers/${quote.customer.id}`" class="text-lavoro-darkerblue">{{ quote.customer.name }}</Link>
                · {{ nlDate(quote.quote_date) }}
                <template v-if="quote.valid_until"> · geldig tot {{ nlDate(quote.valid_until) }}</template>
            </p>
        </div>
        <div v-if="can.update" class="flex flex-wrap gap-2">
            <Link v-if="serviceOrder" :href="`/serviceorders/${serviceOrder.id}`"
                class="px-3 py-2 rounded-md text-sm font-medium border border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-800">
                Werkbon {{ serviceOrder.number }}
            </Link>
            <button v-else-if="quote.status === 'Geaccepteerd' && can.createServiceOrder" type="button" @click="convert"
                class="px-3 py-2 rounded-md text-sm font-medium bg-green-600 text-white hover:bg-green-700">
                Werkbon maken
            </button>
            <button v-if="quote.status !== 'Geaccepteerd'" type="button" @click="sending = true"
                class="px-3 py-2 rounded-md text-sm font-medium bg-lavoro-blue text-white hover:opacity-90">
                {{ quote.sent_at ? 'Opnieuw versturen' : 'Versturen' }}
            </button>
            <button v-if="acceptable" type="button" @click="accepting = true"
                class="px-3 py-2 rounded-md text-sm font-medium border border-green-600 text-green-700 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-950">
                Akkoord vastleggen
            </button>
            <button v-for="status in nextStatuses" :key="status" type="button" @click="moveTo(status)"
                :class="status === 'Concept'
                    ? 'border border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-800'
                    : 'border border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-800'"
                class="px-3 py-2 rounded-md text-sm font-medium">
                {{ STATUS_ACTIONS[status] }}
            </button>
            <button v-if="can.delete && !quote.sent_at" type="button" @click="destroy"
                class="px-3 py-2 rounded-md text-sm font-medium text-red-600 border border-red-200 dark:border-red-900 hover:bg-red-50 dark:hover:bg-red-950">
                Verwijderen
            </button>
        </div>
    </div>

    <p v-if="!editable && can.update" class="mb-4 text-sm text-gray-500 dark:text-slate-400">
        Deze offerte is {{ quote.status.toLowerCase() }}. Zet hem terug naar concept om hem aan te passen.
    </p>

    <QuoteSendDrawer v-if="can.update" v-model="sending" :quote-id="quote.id" :defaults="sendDefaults" />
    <QuoteAcceptDialog v-if="can.update" v-model="accepting" :quote-id="quote.id" :lines="lines" :totals="totals"
        :default-name="quote.contact?.full_name ?? ''" :can-create-service-order="can.createServiceOrder" />

    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent padding="px-0 py-0 sm:px-0 sm:pb-0 xl:px-0 xl:pt-0 xl:pb-0">
                <div class="p-4">
                    <SectionHeader :icon="ListIcon" title="Regels" chapter="products" flush
                        subtitle="Prijzen zijn overgenomen uit de catalogus op het moment van toevoegen." />
                </div>
                <div class="hidden md:grid grid-cols-12 gap-2 px-4 pb-2 text-xs font-bold text-gray-500 dark:text-slate-400">
                    <div class="col-span-4">Omschrijving</div>
                    <div class="col-span-1 text-right">Aantal</div>
                    <div class="col-span-1">Eenheid</div>
                    <div class="col-span-2 text-right">Prijs</div>
                    <div class="col-span-1 text-right">Korting</div>
                    <div class="col-span-1">Btw</div>
                    <div class="col-span-1 text-right">Totaal</div>
                </div>
                <template v-for="line in lines" :key="line.id + '-' + line.updated_at">
                    <QuoteLineRow :quote-id="quote.id" :line="line" :vat-rates="vatRates" :editable="canEdit"
                        :see-margins="can.seeMargins" />
                    <QuoteLineRow v-for="child in line.children" :key="child.id + '-' + child.updated_at"
                        :quote-id="quote.id" :line="child" :vat-rates="vatRates" child :editable="canEdit"
                        :see-margins="can.seeMargins" :muted="line.is_optional" />
                </template>
                <p v-if="!lines.length" class="px-4 py-6 text-sm text-center text-gray-400 dark:text-slate-500">
                    Nog niets aangeboden.
                </p>
                <QuoteAddBar v-if="canEdit" :quote-id="quote.id" />
            </BoxComponent>

            <BoxComponent class="mt-4">
                <SectionHeader :icon="TextIcon" title="Teksten" chapter="details"
                    subtitle="Boven en onder de regels, zoals de klant ze leest." />
                <label class="text-xs font-bold text-slate-400">Openingstekst</label>
                <textarea v-model="form.intro_text" :disabled="!canEdit" @change="save('intro_text')" rows="4"
                    :class="textarea" />
                <label class="mt-3 block text-xs font-bold text-slate-400">Slottekst</label>
                <textarea v-model="form.closing_text" :disabled="!canEdit" @change="save('closing_text')" rows="4"
                    :class="textarea" />
            </BoxComponent>
        </template>

        <template #sidebar>
            <BoxComponent v-if="quote.accepted_name" class="mb-4">
                <SectionHeader :icon="PenLineIcon" title="Akkoord" chapter="signoff"
                    :subtitle="`${quote.accepted_name}, ${acceptedVia}, ${nlDate(quote.decided_at)}`" />
                <img v-if="quote.accepted_signature" :src="quote.accepted_signature" alt="Handtekening"
                    class="w-full rounded border border-gray-200 dark:border-slate-700 bg-white" />
            </BoxComponent>

            <BoxComponent v-if="answers.length" class="mb-4">
                <SectionHeader :icon="MessageSquareIcon" title="Reacties van de klant" chapter="remarks" />
                <div v-for="(answer, index) in answers" :key="index"
                    class="py-2 text-sm border-b last:border-b-0 border-gray-100 dark:border-slate-800">
                    <div class="font-medium">
                        {{ answer.name }} {{ answer.kind === 'accepted' ? 'ging akkoord' : 'vroeg een wijziging' }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-slate-400">{{ nlDate(answer.answered_at) }} · {{ answer.recipient }}</div>
                    <p v-if="answer.message" class="mt-1 whitespace-pre-line">{{ answer.message }}</p>
                </div>
            </BoxComponent>

            <BoxComponent>
                <SectionHeader :icon="EuroIcon" title="Totaal" chapter="financial" />
                <dl class="text-sm divide-y divide-gray-100 dark:divide-slate-800">
                    <div class="flex justify-between py-1.5">
                        <dt>Totaal excl. btw</dt><dd class="tabular-nums">{{ nlCurrency(totals.net) }}</dd>
                    </div>
                    <div v-for="row in totals.vat" :key="row.rate" class="flex justify-between py-1.5 text-gray-500">
                        <dt>Btw {{ row.rate }}% over {{ nlCurrency(row.net) }}</dt><dd class="tabular-nums">{{ nlCurrency(row.vat) }}</dd>
                    </div>
                    <div class="flex justify-between py-1.5 font-bold">
                        <dt>Totaal incl. btw</dt><dd class="tabular-nums">{{ nlCurrency(totals.gross) }}</dd>
                    </div>
                    <div v-if="totals.optional_net" class="flex justify-between py-1.5 text-amber-700 dark:text-amber-400">
                        <dt>Optioneel, excl. btw</dt><dd class="tabular-nums">{{ nlCurrency(totals.optional_net) }}</dd>
                    </div>
                    <template v-if="can.seeMargins">
                        <div class="flex justify-between py-1.5 text-gray-500">
                            <dt>Inkoop</dt><dd class="tabular-nums">{{ nlCurrency(totals.cost) }}</dd>
                        </div>
                        <div class="flex justify-between py-1.5 text-gray-500">
                            <dt>Marge</dt>
                            <dd class="tabular-nums">{{ nlCurrency(totals.margin) }}<span v-if="totals.net"> ({{ marginShare }})</span></dd>
                        </div>
                    </template>
                </dl>
            </BoxComponent>

            <BoxComponent class="mt-4">
                <SectionHeader :icon="InfoIcon" title="Gegevens" chapter="customer" />
                <div class="flex flex-col gap-3">
                    <label class="flex items-center justify-between gap-2 text-sm">
                        <span>Klant kiest zelf de opties</span>
                        <SwitchComponent v-model="form.customer_picks_options" :disabled="!canEdit"
                            @update:model-value="save('customer_picks_options')" />
                    </label>
                    <div>
                        <span class="text-xs font-bold text-slate-400">Titel</span>
                        <TextInput v-model="form.title" :disabled="!canEdit" @change="save('title')"
                            :has-error="Boolean(form.errors.title)" :error-message="form.errors.title" />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <span class="text-xs font-bold text-slate-400">Offertedatum</span>
                            <TextInput v-model="form.quote_date" type="date" :disabled="!canEdit"
                                @change="save('quote_date')"
                                :has-error="Boolean(form.errors.quote_date)" :error-message="form.errors.quote_date" />
                        </div>
                        <div>
                            <span class="text-xs font-bold text-slate-400">Geldig tot</span>
                            <TextInput v-model="form.valid_until" type="date" :disabled="!canEdit"
                                @change="save('valid_until')"
                                :has-error="Boolean(form.errors.valid_until)" :error-message="form.errors.valid_until" />
                        </div>
                    </div>
                    <div>
                        <span class="text-xs font-bold text-slate-400">Locatie</span>
                        <ComboBox :options="locationOptions" v-model="form.location_id" placeholder="Hoofdadres van de klant"
                            :disabled="!canEdit"
                            :has-error="Boolean(form.errors.location_id)" :error-message="form.errors.location_id" />
                    </div>
                    <div>
                        <span class="text-xs font-bold text-slate-400">Contactpersoon</span>
                        <ComboBox :options="contactOptions" v-model="form.contact_id" placeholder="Geen contactpersoon"
                            :disabled="!canEdit"
                            :has-error="Boolean(form.errors.contact_id)" :error-message="form.errors.contact_id" />
                    </div>
                    <div>
                        <span class="text-xs font-bold text-slate-400">Project</span>
                        <ComboBox :options="projectOptions" v-model="form.project_id" placeholder="Geen project"
                            :disabled="!canEdit"
                            :has-error="Boolean(form.errors.project_id)" :error-message="form.errors.project_id" />
                    </div>
                </div>
            </BoxComponent>

            <BoxComponent class="mt-4">
                <SectionHeader :icon="HistoryIcon" title="Tijdlijn" chapter="timeline" />
                <TimelineComponent v-if="quote.activities?.length" :activities="quote.activities" />
                <p v-else class="text-sm text-gray-400 dark:text-slate-500">Nog niets gebeurd.</p>
            </BoxComponent>
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { EuroIcon, HistoryIcon, InfoIcon, ListIcon, MessageSquareIcon, PenLineIcon, TextIcon } from '@lucide/vue'
import BreadcrumbComponent from '@/Components/UI/BreadcrumbComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue'
import QuoteLineRow from '@/Components/Quotes/QuoteLineRow.vue'
import QuoteAddBar from '@/Components/Quotes/QuoteAddBar.vue'
import QuoteAcceptDialog from '@/Components/Quotes/QuoteAcceptDialog.vue'
import QuoteSendDrawer from '@/Components/Quotes/QuoteSendDrawer.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import { nlCurrency, nlDate, quoteStatusClass } from '@/Utilities/Utilities'

const props = defineProps({
    quote: { type: Object, required: true },
    lines: { type: Array, required: true },
    totals: { type: Object, required: true },
    vatRates: { type: Array, required: true },
    answers: { type: Array, default: () => [] },
    acceptedVia: { type: String, default: null },
    sendDefaults: { type: Object, required: true },
    serviceOrder: { type: Object, default: null },
    acceptable: { type: Boolean, default: false },
    nextStatuses: { type: Array, default: () => [] },
    editable: { type: Boolean, default: false },
    can: { type: Object, required: true },
})

/** What a plain status button says. Sending and accepting have buttons of their own. */
const STATUS_ACTIONS = {
    Concept: 'Terug naar concept',
    Verzonden: 'Markeer als verzonden',
    Afgewezen: 'Afgewezen',
    Vervallen: 'Vervallen',
}

const sending = ref(false)
const accepting = ref(false)

const textarea = 'mt-1 block w-full rounded-md border-0 ring-1 ring-inset ring-gray-300 dark:ring-slate-600 dark:bg-slate-900 text-sm p-2 disabled:bg-gray-50 dark:disabled:bg-slate-800'

const canEdit = computed(() => props.editable && props.can.update)

const form = useForm({
    title: props.quote.title,
    quote_date: props.quote.quote_date,
    valid_until: props.quote.valid_until,
    location_id: props.quote.location_id,
    contact_id: props.quote.contact_id,
    project_id: props.quote.project_id,
    intro_text: props.quote.intro_text,
    closing_text: props.quote.closing_text,
    customer_picks_options: props.quote.customer_picks_options,
})

const locationOptions = computed(() => (props.quote.customer.locations ?? []).map(location => ({
    id: location.id,
    name: [location.title, location.address, location.city].filter(Boolean).join(', '),
})))
const contactOptions = computed(() => (props.quote.customer.contacts ?? []).map(contact => ({
    id: contact.id,
    name: contact.full_name,
})))
const projectOptions = computed(() => (props.quote.customer.projects ?? []).map(project => ({
    id: project.id,
    name: `${project.number} ${project.title}`,
})))

const marginShare = computed(() => Math.round((props.totals.margin / props.totals.net) * 100) + '%')

function save(name) {
    if ((form[name] ?? null) === (props.quote[name] ?? null)) return
    form.transform(data => ({ [name]: data[name] === '' ? null : data[name] }))
        .patch(`/quotes/${props.quote.id}`, { preserveScroll: true, preserveState: true })
}

/** Picking toggles: clicking the chosen location, contact or project again clears it. */
watch(() => [form.location_id, form.contact_id, form.project_id], () => {
    ;['location_id', 'contact_id', 'project_id'].forEach(save)
})

function moveTo(status) {
    router.patch(`/quotes/${props.quote.id}/status`, { status }, { preserveScroll: true })
}

function convert() {
    if (!confirm(`Van offerte ${props.quote.number} een werkbon maken?`)) return
    router.post(`/quotes/${props.quote.id}/service-order`)
}

function destroy() {
    if (!confirm(`Offerte ${props.quote.number} verwijderen? Dit kan niet ongedaan worden gemaakt.`)) return
    router.delete(`/quotes/${props.quote.id}`)
}
</script>
