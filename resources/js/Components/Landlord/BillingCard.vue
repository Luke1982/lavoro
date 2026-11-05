<template>
    <PanelSection title="Facturatie">

        <p v-if="billing.demo">
            <strong>Wordt niet gefactureerd</strong>
            <span class="text-slate-500">
                &mdash; de demo wordt elke nacht opnieuw opgebouwd, en elke factuur kost een
                nummer uit de doorlopende reeks.
            </span>
        </p>
        <p v-else-if="billing.next">
            Volgende factuur, vanaf <strong>{{ nlDate(billing.next_on) }}</strong>:
        </p>
        <p v-else-if="!tenant.subscription_started_on" class="text-slate-500">
            Geen ingangsdatum: deze klant wordt niet gefactureerd.
        </p>
        <p v-else class="text-slate-500">Er staat geen factuur meer gepland.</p>

        <p v-if="!billing.demo && tenant.auto_invoicing === false" class="mt-1 text-sm font-semibold text-amber-700">
            Automatisch factureren staat uit: de factuur maak je zelf aan bij Facturen bekijken.
        </p>

        <table v-if="billing.next || billing.pending.length" class="mt-2 w-full text-sm">
            <tbody>
                <tr v-for="(line, index) in billing.next?.lines ?? []" :key="index"
                    class="border-t border-slate-200 align-top">
                    <td class="py-1.5">{{ line.description }}</td>
                    <td class="py-1.5 pl-3 text-right whitespace-nowrap">{{ euro(line.amount_cents) }}</td>
                    <td></td>
                </tr>
                <tr v-for="charge in billing.pending" :key="`charge-${charge.id}`"
                    class="border-t border-slate-200 align-top">
                    <td class="py-1.5">{{ charge.description }}</td>
                    <td class="py-1.5 pl-3 text-right whitespace-nowrap">{{ euro(charge.amount_cents) }}</td>
                    <td class="py-1.5 pl-3 text-right">
                        <button type="button" class="text-red-700 underline" @click="forget(charge)">verwijderen</button>
                    </td>
                </tr>
                <template v-if="billing.next">
                    <tr v-if="billing.next.discount_cents" class="border-t border-slate-200">
                        <td class="py-1.5">Jaarkorting</td>
                        <td class="py-1.5 pl-3 text-right whitespace-nowrap text-red-700">
                            &minus; {{ euro(billing.next.discount_cents) }}
                        </td>
                        <td></td>
                    </tr>
                    <tr class="border-t border-slate-300 font-semibold">
                        <td class="py-1.5">Totaal excl. btw</td>
                        <td class="py-1.5 pl-3 text-right whitespace-nowrap">{{ euro(billing.next.total_cents) }}</td>
                        <td></td>
                    </tr>
                </template>
            </tbody>
        </table>

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
