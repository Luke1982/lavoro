<template>
    <div class="@container text-sm">
        <div class="hidden @xl:flex gap-3 pb-2 text-xs font-bold text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-700">
            <div class="flex-1">Omschrijving</div>
            <div v-for="column in columns" :key="column.key" :class="column.cell">{{ column.label }}</div>
        </div>

        <template v-for="line in lines" :key="line.id">
            <div v-if="line.kind === 'text'" class="pt-4 pb-1">
                <div class="font-semibold text-slate-800 dark:text-slate-100">{{ line.description }}</div>
                <div v-if="line.details" class="rich-text text-slate-600 dark:text-slate-300" v-html="line.details" />
            </div>
            <template v-else>
                <p v-if="startsChoice(line)" class="pt-3 text-xs font-semibold uppercase tracking-wide text-fuchsia-700 dark:text-fuchsia-300">
                    Kies één van deze mogelijkheden
                </p>
                <div class="flex flex-wrap @xl:flex-nowrap gap-x-3 gap-y-1 py-2 border-b border-slate-100 dark:border-slate-800"
                    :class="[isOut(line) ? 'text-slate-400 dark:text-slate-500' : '', line.choice_group ? 'pl-2 border-l-2 border-l-fuchsia-300 dark:border-l-fuchsia-700' : '']">
                    <label class="flex min-w-0 flex-1 basis-full @xl:basis-auto items-start gap-2"
                        :class="pickable && isChoosable(line) ? 'cursor-pointer' : ''">
                        <input v-if="pickable && line.choice_group" type="radio" class="mt-1" :name="`alternatives-${line.choice_group}`"
                            :checked="chosen.includes(line.id)" @change="pick(line)" />
                        <input v-else-if="pickable && line.is_optional" type="checkbox" class="mt-1"
                            :checked="chosen.includes(line.id)" @change="toggle(line.id)" />
                        <span class="min-w-0">
                            {{ line.description }}
                            <span v-if="line.is_optional" class="ml-1 rounded-full bg-sky-100 dark:bg-sky-900/40 px-2 py-0.5 text-xs text-sky-700 dark:text-sky-300">optie</span>
                            <span v-if="line.is_provisional" class="ml-1 rounded-full px-2 py-0.5 text-xs" :class="PROVISIONAL_BADGE">stelpost</span>
                            <span v-if="line.discount_percent && layout.discount" class="ml-1 text-xs text-slate-500">{{ nlQuantity(line.discount_percent) }}% korting</span>
                            <span v-if="line.location_note" class="block text-xs text-slate-500 dark:text-slate-400">Ruimte: {{ line.location_note }}</span>
                            <span v-if="line.details" class="block text-xs text-slate-500 dark:text-slate-400 whitespace-pre-line">{{ line.details }}</span>
                        </span>
                    </label>
                    <div class="ml-auto flex flex-wrap justify-end gap-x-3 gap-y-1">
                        <div v-for="column in columns" :key="column.key" :class="[column.cell, column.key === 'line_total' ? 'font-medium' : '']">
                            <span class="block text-[0.65rem] font-normal text-slate-400 @xl:hidden">{{ column.label }}</span>
                            {{ column.value(line) }}
                        </div>
                    </div>
                </div>
                <div v-for="child in line.children" :key="child.id"
                    class="flex flex-wrap @xl:flex-nowrap gap-x-3 gap-y-1 py-1.5 text-xs border-b border-slate-100 dark:border-slate-800"
                    :class="isOut(line) ? 'text-slate-400 dark:text-slate-500' : 'text-slate-600 dark:text-slate-300'">
                    <div class="min-w-0 flex-1 basis-full @xl:basis-auto pl-6">{{ child.description }}</div>
                    <div class="ml-auto flex flex-wrap justify-end gap-x-3 gap-y-1">
                        <div v-for="column in columns" :key="column.key" :class="column.cell">{{ column.value(child) }}</div>
                    </div>
                </div>
            </template>
        </template>

        <dl class="mt-4 ml-auto w-full @xl:w-72 space-y-1">
            <div class="flex justify-between"><dt>Totaal excl. btw</dt><dd class="tabular-nums">{{ nlCurrency(live.net) }}</dd></div>
            <div v-for="row in live.vat" :key="row.rate" class="flex justify-between text-slate-500">
                <dt>Btw {{ row.rate }}%</dt><dd class="tabular-nums">{{ nlCurrency(row.vat) }}</dd>
            </div>
            <div class="flex justify-between font-bold border-t border-slate-200 dark:border-slate-700 pt-1">
                <dt>Totaal incl. btw</dt><dd class="tabular-nums">{{ nlCurrency(live.gross) }}</dd>
            </div>
            <div v-if="totals.provisional_net" class="flex justify-between text-xs text-slate-500">
                <dt>Waarvan stelposten, excl. btw</dt><dd class="tabular-nums">{{ nlCurrency(totals.provisional_net) }}</dd>
            </div>
        </dl>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { PROVISIONAL_BADGE, isChoosable } from '@/Components/Quotes/quoteColumns'
import { nlCurrency, nlQuantity } from '@/Utilities/Utilities'

/**
 * A quote as the customer reads it: the lines, the materials under a product,
 * and the totals, in the columns the quote shows. What counts starts ticked
 * (the parent passes it in); with pickable, every option gets a box and every
 * set of alternatives a round button each, and the totals follow what is
 * picked: what the server counted, plus each line picked since and minus each
 * one let go, with the VAT counted again per rate the way the server does it,
 * over the sum and not per line.
 *
 * It lays itself out by its own width, not the screen's: in a narrow sidebar
 * or on a phone the amounts go under the description, each with its label,
 * and wrap instead of running off the edge.
 */
const props = defineProps({
    lines: { type: Array, required: true },
    totals: { type: Object, required: true },
    pickable: { type: Boolean, default: false },
    /** Which columns show and whether prices include VAT, as QuoteLayout completes them. */
    layout: { type: Object, required: true },
})

const chosen = defineModel({ type: Array, default: () => [] })

/** Fixed widths where there is room, so the materials under a product, indented, still line up with the product above them. */
const CELL = {
    quantity: '@xl:w-24 shrink-0 text-right tabular-nums',
    amount: '@xl:w-28 shrink-0 text-right tabular-nums',
    narrow: '@xl:w-14 shrink-0 text-right tabular-nums',
}

const price = (line, amount) => nlCurrency(props.layout.incl_vat ? amount * (1 + Number(line.vat_percent) / 100) : amount)

const columns = computed(() => [
    { key: 'quantity', label: 'Aantal', cell: CELL.quantity, value: line => `${nlQuantity(line.quantity)} ${line.unit ?? ''}` },
    { key: 'unit_price', label: 'Prijs', cell: CELL.amount, value: line => price(line, line.unit_price) },
    { key: 'vat', label: 'Btw', cell: CELL.narrow, value: line => `${nlQuantity(line.vat_percent)}%` },
    { key: 'line_total', label: 'Totaal', cell: CELL.amount, value: line => price(line, line.net) },
].filter(column => props.layout[column.key]))

const isOut = (line) => isChoosable(line) && !chosen.value.includes(line.id)

/** The first line of a set of alternatives says what the set is for. */
const startsChoice = (line) => Boolean(line.choice_group)
    && props.lines.find(other => other.choice_group === line.choice_group)?.id === line.id

function toggle(id) {
    chosen.value = chosen.value.includes(id) ? chosen.value.filter(other => other !== id) : [...chosen.value, id]
}

function pick(line) {
    const others = props.lines.filter(other => other.choice_group === line.choice_group).map(other => other.id)
    chosen.value = [...chosen.value.filter(id => !others.includes(id)), line.id]
}

const cents = (value) => Math.round(Number(value) * 100)

const live = computed(() => {
    const per_rate = new Map(props.totals.vat.map(row => [Number(row.rate), cents(row.net)]))
    let net = cents(props.totals.net)

    props.lines
        .filter(line => isChoosable(line) && (line.is_chosen === true) !== chosen.value.includes(line.id))
        .forEach(changed => {
            const sign = chosen.value.includes(changed.id) ? 1 : -1
            ;[changed, ...changed.children].forEach(line => {
                net += sign * cents(line.net)
                per_rate.set(Number(line.vat_percent), (per_rate.get(Number(line.vat_percent)) ?? 0) + sign * cents(line.net))
            })
        })

    const vat = [...per_rate.entries()]
        .filter(([, rate_net]) => rate_net !== 0)
        .sort(([a], [b]) => b - a)
        .map(([rate, rate_net]) => ({ rate, vat: Math.round(rate_net * rate / 100) / 100 }))
    const vat_cents = vat.reduce((sum, row) => sum + cents(row.vat), 0)

    return { net: net / 100, vat, gross: (net + vat_cents) / 100 }
})
</script>
