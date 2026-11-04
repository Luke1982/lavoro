<template>
    <PanelSection title="Facturatie">

        <p v-if="billing.demo">
            <strong>Wordt niet gefactureerd</strong>
            <span class="text-slate-500">
                &mdash; de demo wordt elke nacht opnieuw opgebouwd, en elke factuur kost een
                nummer uit de doorlopende reeks.
            </span>
        </p>
        <p v-else-if="billing.next_on">
            Volgende factuur: <strong>{{ euro(billing.next_cents) }}</strong>
            <span class="text-slate-500">excl. btw, vanaf {{ nlDate(billing.next_on) }}</span>
        </p>
        <p v-else class="text-slate-500">Er staat geen factuur meer gepland.</p>

        <template v-if="billing.pending.length">
            <p class="mt-3 text-sm text-slate-500">Nog te verrekenen:</p>
            <table class="w-full">
                <tr v-for="charge in billing.pending" :key="charge.id" class="border-t border-slate-200 align-top">
                    <td class="py-1.5">{{ charge.description }}</td>
                    <td class="py-1.5 pl-3 text-right whitespace-nowrap">{{ euro(charge.amount_cents) }}</td>
                    <td class="py-1.5 pl-3 text-right">
                        <button type="button" class="text-sm text-red-700 underline" @click="forget(charge)">
                            verwijderen
                        </button>
                    </td>
                </tr>
            </table>
        </template>

        <p class="mt-3">
            <Link :href="`/beheer/${tenant.id}/facturen`" class="text-blue-700 underline">Facturen bekijken</Link>
        </p>
    </PanelSection>
</template>

<script setup>
import PanelSection from '@/Components/Landlord/PanelSection.vue'
import { Link, router } from '@inertiajs/vue3'
import { nlDate } from '@/Utilities/Utilities'
import { euro } from './money.js'

const props = defineProps({
    tenant: { type: Object, required: true },
    billing: { type: Object, required: true },
})

/**
 * Een creditfactuur zet alles wat de foute factuur verrekende weer klaar. Zat
 * de fout juist in zo'n regel, dan haal je hem hier weg voor de volgende factuur.
 */
const forget = (charge) => {
    if (!confirm(`"${charge.description}" verwijderen? Hij komt dan niet op de volgende factuur.`)) {
        return
    }

    router.delete(`/beheer/${props.tenant.id}/verrekening/${charge.id}`, { preserveScroll: true })
}
</script>
