<template>
    <Head :title="`Facturen ${tenant.name}`" />

    <h2 class="mb-1 text-xl font-semibold">Facturen &middot; {{ tenant.name }}</h2>
    <p class="mb-5 text-sm">
        <Link :href="`/beheer/${tenant.id}`" class="text-blue-700 underline">terug naar het abonnement</Link>
    </p>

    <div v-if="unbilled.length"
        class="mb-5 rounded-lg border border-slate-200 border-l-4 border-l-red-700 bg-white p-5">
        <strong>Deze periodes zijn nooit gefactureerd.</strong>
        Er wordt altijd maar één periode tegelijk gefactureerd, die van vandaag; een overgeslagen
        maand komt uit zichzelf niet meer terug.
        <ul class="mt-2 list-inside list-disc">
            <li v-for="period in unbilled" :key="period">{{ period }}</li>
        </ul>
    </div>

    <PanelSection title="Eerstvolgende factuur">

        <table class="w-full text-left">
            <tbody>
                <tr v-for="(line, index) in preview.lines" :key="index" class="border-b border-slate-200">
                    <td class="py-2">{{ line.description }}</td>
                    <td class="w-36 py-2 text-right">{{ euro(line.amount_cents) }}</td>
                </tr>
                <tr v-if="preview.discount_cents" class="border-b border-slate-200">
                    <td class="py-2">Jaarkorting</td>
                    <td class="py-2 text-right text-red-700">&minus; {{ euro(preview.discount_cents) }}</td>
                </tr>
                <tr class="border-b border-slate-200">
                    <td class="py-2">Netto</td>
                    <td class="py-2 text-right">{{ euro(preview.total_cents) }}</td>
                </tr>
                <tr class="border-b border-slate-200">
                    <td class="py-2">BTW {{ preview.vat_percent }}%</td>
                    <td class="py-2 text-right">{{ euro(preview.vat_cents) }}</td>
                </tr>
                <tr>
                    <td class="py-2 font-semibold">Te betalen</td>
                    <td class="py-2 text-right font-semibold">{{ euro(preview.gross_cents) }}</td>
                </tr>
            </tbody>
        </table>

        <p v-if="is_credit" class="mt-4 text-sm text-slate-500">
            Dit wordt een creditfactuur: er gaat geld terug in plaats van heen. Incasseren kan er
            niet mee &mdash; terugstorten gaat met de hand.
        </p>

        <button v-if="is_due" type="button" :disabled="issuing" @click="issue"
            class="panel-button mt-4">{{ is_credit ? 'Creditfactuur aanmaken' : 'Factuur aanmaken' }}</button>

        <p v-else-if="!tenant.subscription_started_on"
            class="mt-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900">
            Deze klant heeft geen ingangsdatum, dus er wordt nooit iets gefactureerd &mdash; ook niet
            na een pakketwissel. Vul de ingangsdatum in bij
            <Link :href="`/beheer/${tenant.id}`" class="underline">het abonnement</Link>.
        </p>

        <p v-else class="mt-4 text-sm text-slate-500">
            Deze periode is al gefactureerd en er staat niets nieuws open. De volgende factuur kan
            vanaf <strong>{{ nlDate(next_period_starts_on) }}</strong>, of eerder zodra er iets
            verandert &mdash; een pakketwissel of bijgekocht AI-tegoed.
        </p>
    </PanelSection>

    <PanelSection title="Aangemaakt" class="mt-5">

        <p v-if="!invoices.length" class="text-slate-500">Nog geen facturen.</p>

        <!--
            Een tabel en geen rij losse blokken: met flex bepaalde elke factuur
            zelf waar zijn datum en bedrag terechtkwamen, en dan staat niets
            onder elkaar zodra de nummers of de bedragen verschillen.
        -->
        <table v-if="invoices.length" class="w-full text-left">
            <tbody>
                <template v-for="invoice in invoices" :key="invoice.id">
                    <tr class="border-t border-slate-200 align-top">
                        <td class="w-16 py-3">
                            <a :href="preview(invoice)" target="_blank" rel="noopener"
                                :title="`Bekijk ${invoice.number}`"
                                class="block h-28 w-20 overflow-hidden rounded border border-slate-200 bg-white">
                                <iframe :src="`${preview(invoice)}#toolbar=0&navpanes=0&scrollbar=0&view=FitH`"
                                    class="pointer-events-none h-[453px] w-[320px] origin-top-left scale-[0.25]"
                                    loading="lazy" tabindex="-1" :title="`Voorbeeld van ${invoice.number}`" />
                            </a>
                        </td>
                        <td class="py-3 pr-3 font-semibold">{{ invoice.number }}</td>
                        <td class="py-3 pr-3 whitespace-nowrap text-slate-500">{{ nlDate(invoice.issued_on) }}</td>
                        <td class="py-3 pr-3 whitespace-nowrap text-right font-semibold">
                            {{ euro(invoice.gross_cents) }}
                        </td>
                        <td class="py-3 whitespace-nowrap text-right text-sm">
                            <a :href="`/beheer/${tenant.id}/facturen/${invoice.id}/pdf`"
                                class="text-blue-700 underline">pdf</a>
                            &middot;
                            <a :href="`/beheer/${tenant.id}/facturen/${invoice.id}/xml`"
                                class="text-blue-700 underline">xml</a>
                            &middot;
                            <span v-if="invoice.mailed_at" class="text-slate-500">
                                verstuurd {{ nlDate(invoice.mailed_at) }} {{ nlTime(invoice.mailed_at) }}
                            </span>
                            <button v-else type="button" :disabled="mailing === invoice.id" @click="mail(invoice)"
                                class="text-blue-700 underline disabled:opacity-60">versturen</button>
                            <template v-if="!invoice.mailed_at && !invoice.collected_at">
                                &middot;
                                <button type="button" @click="remove(invoice)"
                                    class="text-red-700 underline">verwijderen</button>
                            </template>
                        </td>
                    </tr>

                    <tr>
                        <td></td>
                        <td colspan="4" class="pb-3">
                            <div v-for="line in invoice.lines" :key="line.id"
                                class="flex justify-between gap-6 py-0.5 text-sm text-slate-500">
                                <span>{{ line.description }}</span>
                                <span class="whitespace-nowrap">{{ euro(line.amount_cents) }}</span>
                            </div>

                            <p v-if="invoice.mail_error" class="pt-1 text-sm font-semibold text-red-700">
                                Versturen mislukt: {{ invoice.mail_error }}
                            </p>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </PanelSection>
</template>

<script setup>
import PanelSection from '@/Components/Landlord/PanelSection.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { nlDate, nlTime } from '@/Utilities/Utilities'
import { euro } from '@/Components/Landlord/money.js'

const props = defineProps({
    tenant: { type: Object, required: true },
    invoices: { type: Array, required: true },
    preview: { type: Object, required: true },
    is_due: { type: Boolean, required: true },
    is_credit: { type: Boolean, default: false },
    next_period_starts_on: { type: String, required: true },
    unbilled: { type: Array, default: () => [] },
})

const preview = (invoice) => `/beheer/${props.tenant.id}/facturen/${invoice.id}/voorbeeld`

const issuing = ref(false)
const mailing = ref(null)

const issue = () => router.post(`/beheer/${props.tenant.id}/facturen`, {}, {
    preserveScroll: true,
    onStart: () => { issuing.value = true },
    onFinish: () => { issuing.value = false },
})

const mail = (invoice) => router.post(`/beheer/${props.tenant.id}/facturen/${invoice.id}/mail`, {}, {
    preserveScroll: true,
    onStart: () => { mailing.value = invoice.id },
    onFinish: () => { mailing.value = null },
})

const remove = (invoice) => {
    if (!confirm(`Factuur ${invoice.number} verwijderen? Het nummer blijft vergeven; `
        + 'de volgende factuur krijgt het daaropvolgende.')) {
        return
    }

    router.delete(`/beheer/${props.tenant.id}/facturen/${invoice.id}`, { preserveScroll: true })
}
</script>
