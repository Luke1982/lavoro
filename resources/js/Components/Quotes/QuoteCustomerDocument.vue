<template>
    <div>
        <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ quote.title }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Offerte {{ quote.number }}, versie {{ quote.version }} voor {{ quote.contact || quote.customer }}, {{ nlDateMedium(quote.quote_date) }}
            <template v-if="quote.valid_until">· geldig tot en met {{ nlDateMedium(quote.valid_until) }}</template>
        </p>
        <p v-if="quote.location" class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Locatie: {{ quote.location }}</p>

        <slot name="notice" />

        <div v-if="quote.intro_text" class="rich-text mt-6 text-sm text-slate-700 dark:text-slate-300" v-html="quote.intro_text" />

        <QuoteDocument v-model="shownOptions" class="mt-6" :lines="lines" :totals="totals" :layout="layout" :pickable="pickable" />

        <p v-if="pickable && hasChoices" class="mt-2 text-xs text-slate-500 dark:text-slate-400">
            Kies wat u erbij wilt, en bij een keuze welke u neemt; het totaal rekent mee.
        </p>

        <div v-if="quote.closing_text" class="rich-text mt-6 text-sm text-slate-700 dark:text-slate-300" v-html="quote.closing_text" />

        <section v-if="agreements.length || quote.payment_schedule.length" class="mt-8">
            <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">Afspraken</h2>
            <dl class="mt-2 space-y-1.5 text-sm">
                <div v-for="agreement in agreements" :key="agreement.label" class="grid grid-cols-3 gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">{{ agreement.label }}</dt>
                    <dd class="col-span-2 text-slate-700 dark:text-slate-300">{{ agreement.value }}</dd>
                </div>
                <div v-if="quote.payment_schedule.length" class="grid grid-cols-3 gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Betaaltermijnen</dt>
                    <dd class="col-span-2 space-y-0.5 text-slate-700 dark:text-slate-300">
                        <div v-for="(term, index) in quote.payment_schedule" :key="index" class="flex justify-between gap-3">
                            <span>{{ nlQuantity(term.percent) }}% {{ term.description }}<template v-if="term.before_start"> (vóór de start)</template></span>
                            <span class="tabular-nums">{{ nlCurrency(term.amount) }}</span>
                        </div>
                    </dd>
                </div>
            </dl>
        </section>

        <details v-if="withdrawal" class="mt-6 rounded-xl bg-slate-50 dark:bg-slate-800/60 px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
            <summary class="cursor-pointer font-semibold">U hebt {{ withdrawal.days }} dagen bedenktijd</summary>
            <p v-for="(paragraph, index) in withdrawal.instructions" :key="index" class="mt-2">{{ paragraph }}</p>
            <h3 class="mt-4 font-semibold">Modelformulier voor herroeping</h3>
            <p class="text-xs text-slate-500">Dit formulier alleen invullen en terugzenden als u de overeenkomst wilt herroepen.</p>
            <p v-for="(row, index) in withdrawal.form" :key="`form-${index}`" class="mt-1">{{ row }}</p>
        </details>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import QuoteDocument from '@/Components/Quotes/QuoteDocument.vue'
import { countedOptions, isChoosable } from '@/Components/Quotes/quoteColumns'
import { nlCurrency, nlDateMedium, nlQuantity } from '@/Utilities/Utilities'

/**
 * A quote as the customer reads it, from the heading to the right to change
 * their mind: what QuoteForCustomer gives, so the customer's page and the
 * preview beside the quote show the very same thing. With pickable, the
 * customer ticks optional lines and picks alternatives into the model; without
 * it, what counts is what the quote counts.
 */
const props = defineProps({
    quote: { type: Object, required: true },
    lines: { type: Array, required: true },
    totals: { type: Object, required: true },
    layout: { type: Object, required: true },
    /** The general terms it is offered under: { kind, name, url? }. */
    terms: { type: Object, default: null },
    /** For a private customer: the days to change their mind, and the model form. */
    withdrawal: { type: Object, default: null },
    pickable: { type: Boolean, default: false },
    /** What a stelpost is, as the PDF says it; shown when a line is one. */
    provisional_explanation: { type: String, default: null },
    /** The names of the documents and photos shared with the customer, which go along with the mail. */
    attachments: { type: Array, default: () => [] },
})

const options = defineModel({ type: Array, default: () => [] })

const hasChoices = computed(() => props.lines.some(isChoosable))

const shownOptions = computed({
    get: () => props.pickable ? options.value : countedOptions(props.lines),
    set: (value) => { options.value = value },
})

const termsSentence = computed(() => {
    if (!props.terms) return null
    return props.terms.kind === 'link'
        ? `Op deze offerte zijn onze algemene voorwaarden van toepassing. U leest ze op ${props.terms.url}.`
        : `Op deze offerte zijn onze algemene voorwaarden van toepassing (${props.terms.name}). U ontvangt ze bij deze offerte.`
})

/** The terms an installation quote has to state, those it states. */
const agreements = computed(() => [
    { label: 'Prijs', value: props.quote.pricing_explanation },
    { label: 'Start', value: props.quote.start_period },
    { label: 'Duur', value: props.quote.duration },
    { label: 'Betaling', value: props.quote.payment_terms },
    { label: 'Stelposten', value: props.lines.some(line => line.is_provisional) ? props.provisional_explanation : null },
    { label: 'Voorwaarden', value: termsSentence.value },
    { label: 'Bijlagen', value: props.attachments.length ? `U ontvangt bij deze offerte ook: ${props.attachments.join(', ')}.` : null },
].filter(agreement => agreement.value))
</script>
