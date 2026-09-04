<template>
    <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h3 class="mt-0 mb-3 text-base font-semibold">Facturatie</h3>

        <p>
            Volgende factuur: <strong>{{ euro(billing.next_cents) }}</strong>
            <span class="text-slate-500">
                ({{ tenant.billing_period === 'yearly' ? 'per jaar' : 'per maand' }})
            </span>
        </p>

        <template v-if="billing.pending.length">
            <p class="mt-3 text-sm text-slate-500">Nog te verrekenen:</p>
            <table class="w-full">
                <tr v-for="(charge, index) in billing.pending" :key="index" class="border-t border-slate-200">
                    <td class="py-1.5">{{ charge.description }}</td>
                    <td class="py-1.5 text-right">{{ euro(charge.amount_cents) }}</td>
                </tr>
            </table>
        </template>

        <p class="mt-3">
            <Link :href="`/beheer/${tenant.id}/facturen`" class="text-blue-700 underline">Facturen bekijken</Link>
        </p>
    </section>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import { euro } from './money.js'

defineProps({
    tenant: { type: Object, required: true },
    billing: { type: Object, required: true },
})
</script>
