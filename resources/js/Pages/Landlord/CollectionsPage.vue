<template>
    <Head title="Incasso" />

    <h2 class="mb-1 text-xl font-semibold">Incasso</h2>
    <p class="mb-5 text-sm text-slate-500">
        Facturen van klanten met een machtiging die nog niet in een incassobestand zaten.
        Het bestand (SEPA-XML, pain.008) lever je aan via ASN Online Bankieren.
    </p>

    <div v-if="!issuer.incassant_id"
        class="mb-5 rounded-lg border border-slate-200 border-l-4 border-l-red-700 bg-white p-5">
        Er is nog geen incassant-ID ingesteld. Dat nummer geeft de bank uit bij het
        incassocontract; zonder dat nummer weigert de bank het bestand. Vul het in bij
        <Link href="/beheer/catalogus#facturatie" class="text-blue-700 underline">Catalogus &rarr; Facturatie</Link>.
    </div>

    <p v-if="firstError" class="mb-5 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-red-900">
        {{ firstError }}
    </p>

    <PanelSection>
        <p v-if="!invoices.length" class="text-slate-500">Niets te incasseren.</p>

        <!--
            Een gewoon formulier en geen Inertia-verzending: het antwoord is het
            XML-bestand zelf, en een download kan Inertia niet afhandelen.
        -->
        <form v-else method="post" action="/beheer/incasso" @submit="refreshAfterDownload">
            <input type="hidden" name="_token" :value="csrf">

            <table class="w-full text-left">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="w-8 py-2"></th>
                        <th class="py-2 pr-3">Factuur</th>
                        <th class="py-2 pr-3">Klant</th>
                        <th class="py-2 pr-3">Machtiging</th>
                        <th class="py-2 text-right">Bedrag</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="invoice in invoices" :key="invoice.id" class="border-t border-slate-200">
                        <td class="py-2">
                            <input type="checkbox" name="invoices[]" :value="invoice.id" checked>
                        </td>
                        <td class="py-2 pr-3">{{ invoice.number }}</td>
                        <td class="py-2 pr-3">{{ invoice.tenant.name }}</td>
                        <td class="py-2 pr-3 text-slate-500">
                            {{ invoice.tenant.mandate_reference }} &middot; {{ invoice.tenant.iban }}
                        </td>
                        <td class="py-2 text-right">{{ euro(invoice.gross_cents) }}</td>
                    </tr>
                </tbody>
            </table>

            <div class="mt-4 flex items-center gap-3">
                <label for="collect_on" class="font-semibold">Incassodatum</label>
                <input id="collect_on" type="date" name="collect_on" :value="collect_on"
                    class="panel-field">
                <button type="submit" class="panel-button">
                    Incassobestand maken
                </button>
            </div>

            <p class="mt-2 text-sm text-slate-500">
                Na het maken staan deze facturen op ge&iuml;ncasseerd en komen ze hier niet terug.
            </p>
        </form>
    </PanelSection>
</template>

<script setup>
import PanelSection from '@/Components/Landlord/PanelSection.vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { euro } from '@/Components/Landlord/money.js'

defineProps({
    invoices: { type: Array, required: true },
    issuer: { type: Object, required: true },
    collect_on: { type: String, required: true },
})

const page = usePage()

const csrf = computed(() => page.props.csrf_token)

/** Fouten komen terug na een gewone omleiding, niet via een formulierobject. */
const firstError = computed(() => Object.values(page.props.errors ?? {})[0])

/**
 * Het formulier gaat buiten Inertia om, dus deze pagina hoort niets van de
 * download. Zonder deze verversing blijft de lijst staan met facturen die net
 * geincasseerd zijn.
 */
const refreshAfterDownload = () => {
    window.setTimeout(() => router.reload({ only: ['invoices'] }), 2000)
}
</script>
