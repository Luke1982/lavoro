<template>
    <dl class="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2 2xl:grid-cols-4">
        <div v-for="row in rows" :key="row.label" class="min-w-0">
            <dt class="text-xs text-gray-500 dark:text-slate-400">{{ row.label }}</dt>
            <dd class="mt-0.5 text-lg font-semibold text-gray-800 dark:text-slate-100 tabular-nums">{{ row.value }}</dd>
            <div class="mt-1.5 h-1.5 rounded-full bg-gray-200 dark:bg-slate-700 overflow-hidden">
                <div class="h-full rounded-full transition-all" :class="row.over ? 'bg-red-500' : 'bg-lavoro-blue'"
                    :style="{ width: row.width }" />
            </div>
            <p v-if="row.note" class="mt-1 text-[11px]"
                :class="row.over ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-slate-400'">
                {{ row.note }}
            </p>
        </div>
    </dl>
</template>

<script setup>
import { computed } from 'vue'
import { nlCurrency, nlDecimal } from '@/Utilities/Utilities'

/**
 * Where a project stands, counted the same way as the Excel export: werkbonnen
 * closed, milestones reached, hours worked against the budget (or against what is
 * planned when there is no budget), and money when the reader may see it.
 */
const props = defineProps({
    progress: { type: Object, required: true },
})

function width(part, whole) {
    if (!whole) return '0%'
    return Math.min(100, Math.round((part / whole) * 100)) + '%'
}

const rows = computed(() => {
    const { orders, milestones, hours: time, money } = props.progress
    const hourTarget = time.budget ?? time.planned

    return [
        {
            label: 'Werkbonnen gesloten',
            value: `${orders.closed} van ${orders.total}`,
            width: width(orders.closed, orders.total),
        },
        {
            label: 'Mijlpalen behaald',
            value: `${milestones.reached} van ${milestones.total}`,
            width: width(milestones.reached, milestones.total),
            over: milestones.overdue > 0,
            note: milestones.overdue ? `${milestones.overdue} over datum` : null,
        },
        {
            label: time.budget !== null ? 'Uren gewerkt van begroot' : 'Uren gewerkt van gepland',
            value: `${nlDecimal(time.worked)} van ${nlDecimal(hourTarget)}`,
            width: width(time.worked, hourTarget),
            over: hourTarget > 0 && time.worked > hourTarget,
            note: time.budget !== null ? `${nlDecimal(time.planned)} uur ingepland` : 'Geen begrote uren ingevuld',
        },
        ...(money ? [{
            label: money.budget !== null ? 'Besteed van begroot' : 'Besteed',
            value: money.budget !== null
                ? `${nlCurrency(money.spent)} van ${nlCurrency(money.budget)}`
                : nlCurrency(money.spent),
            width: width(money.spent, money.budget ?? money.spent),
            over: money.budget !== null && money.spent > money.budget,
            note: 'Verkoopwaarde van materialen en diensten op de werkbonnen',
        }] : []),
    ]
})
</script>
