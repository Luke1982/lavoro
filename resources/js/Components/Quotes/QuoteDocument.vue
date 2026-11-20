<template>
    <div class="text-sm">
        <div class="hidden sm:flex gap-3 pb-2 text-xs font-bold text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-700">
            <div class="flex-1">Omschrijving</div>
            <div :class="CELL.quantity">Aantal</div>
            <div :class="CELL.price">Prijs</div>
            <div :class="CELL.total">Totaal</div>
        </div>

        <template v-for="line in lines" :key="line.id">
            <div v-if="line.kind === 'text'" class="pt-4 pb-1">
                <div class="font-semibold text-slate-800 dark:text-slate-100">{{ line.description }}</div>
                <div v-if="line.details" class="rich-text text-slate-600 dark:text-slate-300" v-html="line.details" />
            </div>
            <template v-else>
                <div class="flex flex-wrap sm:flex-nowrap gap-x-3 gap-y-1 py-2 border-b border-slate-100 dark:border-slate-800"
                    :class="isOut(line) ? 'text-slate-400 dark:text-slate-500' : ''">
                    <label class="flex min-w-0 flex-1 basis-full sm:basis-auto items-start gap-2"
                        :class="line.is_optional && pickable ? 'cursor-pointer' : ''">
                        <input v-if="line.is_optional && pickable" type="checkbox" class="mt-1"
                            :checked="chosen.includes(line.id)" @change="toggle(line.id)" />
                        <span class="min-w-0">
                            {{ line.description }}
                            <span v-if="line.is_optional" class="ml-1 rounded-full bg-sky-100 dark:bg-sky-900/40 px-2 py-0.5 text-xs text-sky-700 dark:text-sky-300">optie</span>
                            <span v-if="line.discount_percent" class="ml-1 text-xs text-slate-500">{{ nlQuantity(line.discount_percent) }}% korting</span>
                            <span v-if="line.details" class="block text-xs text-slate-500 dark:text-slate-400 whitespace-pre-line">{{ line.details }}</span>
                        </span>
                    </label>
                    <div class="ml-auto flex gap-3">
                        <div :class="CELL.quantity">{{ nlQuantity(line.quantity) }} {{ line.unit }}</div>
                        <div :class="CELL.price">{{ nlCurrency(line.unit_price) }}</div>
                        <div :class="CELL.total" class="font-medium">{{ nlCurrency(line.net) }}</div>
                    </div>
                </div>
                <div v-for="child in line.children" :key="child.id"
                    class="flex flex-wrap sm:flex-nowrap gap-x-3 gap-y-1 py-1.5 text-xs border-b border-slate-100 dark:border-slate-800"
                    :class="isOut(line) ? 'text-slate-400 dark:text-slate-500' : 'text-slate-600 dark:text-slate-300'">
                    <div class="min-w-0 flex-1 basis-full sm:basis-auto pl-6">{{ child.description }}</div>
                    <div class="ml-auto flex gap-3">
                        <div :class="CELL.quantity">{{ nlQuantity(child.quantity) }} {{ child.unit }}</div>
                        <div :class="CELL.price">{{ nlCurrency(child.unit_price) }}</div>
                        <div :class="CELL.total">{{ nlCurrency(child.net) }}</div>
                    </div>
                </div>
            </template>
        </template>

        <dl class="mt-4 ml-auto w-full sm:w-72 space-y-1">
            <div class="flex justify-between"><dt>Totaal excl. btw</dt><dd class="tabular-nums">{{ nlCurrency(live.net) }}</dd></div>
            <div v-for="row in live.vat" :key="row.rate" class="flex justify-between text-slate-500">
                <dt>Btw {{ row.rate }}%</dt><dd class="tabular-nums">{{ nlCurrency(row.vat) }}</dd>
            </div>
            <div class="flex justify-between font-bold border-t border-slate-200 dark:border-slate-700 pt-1">
                <dt>Totaal incl. btw</dt><dd class="tabular-nums">{{ nlCurrency(live.gross) }}</dd>
            </div>
        </dl>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { nlCurrency, nlQuantity } from '@/Utilities/Utilities'

/**
 * A quote as the customer reads it: the lines, the materials under a product,
 * and the totals. The options that count start ticked (the parent passes them
 * in); with pickable, every option gets a box, and the totals follow what is
 * ticked: what the server counted, plus each option ticked since and minus each
 * one unticked, with the VAT counted again per rate the way the server does it,
 * over the sum and not per line.
 */
const props = defineProps({
    lines: { type: Array, required: true },
    totals: { type: Object, required: true },
    pickable: { type: Boolean, default: false },
})

const chosen = defineModel({ type: Array, default: () => [] })

/** Fixed widths, so the materials under a product, indented, still line up with the product above them. */
const CELL = {
    quantity: 'w-24 shrink-0 text-right tabular-nums',
    price: 'w-28 shrink-0 text-right tabular-nums',
    total: 'w-28 shrink-0 text-right tabular-nums',
}

const isOut = (line) => line.is_optional && !chosen.value.includes(line.id)

function toggle(id) {
    chosen.value = chosen.value.includes(id) ? chosen.value.filter(other => other !== id) : [...chosen.value, id]
}

const cents = (value) => Math.round(Number(value) * 100)

const live = computed(() => {
    const per_rate = new Map(props.totals.vat.map(row => [Number(row.rate), cents(row.net)]))
    let net = cents(props.totals.net)

    props.lines
        .filter(line => line.is_optional && (line.is_chosen === true) !== chosen.value.includes(line.id))
        .forEach(option => {
            const sign = chosen.value.includes(option.id) ? 1 : -1
            ;[option, ...option.children].forEach(line => {
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
