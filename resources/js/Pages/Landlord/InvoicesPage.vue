<template>
    <Head :title="`Facturen ${tenant.name}`" />

    <h2 class="mb-1 text-xl font-semibold">Facturen &middot; {{ tenant.name }}</h2>
    <p class="mb-5 text-sm">
        <Link :href="`/beheer/${tenant.id}`" class="text-blue-700 underline">terug naar het abonnement</Link>
    </p>

    <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h3 class="mb-3 mt-0 text-base font-semibold">Eerstvolgende factuur</h3>

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

        <button v-if="is_due" type="button" :disabled="issuing" @click="issue"
            class="mt-4 rounded-md bg-blue-700 px-4 py-2 text-white disabled:opacity-60">Factuur aanmaken</button>

        <p v-else-if="preview.total_cents < 0" class="mt-4 text-sm text-slate-500">
            Er staat meer tegoed open dan er nu te factureren valt. Er gaat dus niets de deur uit;
            het tegoed blijft staan en gaat van de volgende factuur af.
        </p>

        <p v-else class="mt-4 text-sm text-slate-500">
            Deze periode is al gefactureerd en er staat niets nieuws open. De volgende factuur kan
            vanaf <strong>{{ nlDate(next_period_starts_on) }}</strong>, of eerder zodra er iets
            verandert &mdash; een pakketwissel of bijgekocht AI-tegoed.
        </p>
    </section>

    <section class="mt-5 rounded-lg border border-slate-200 bg-white p-5">
        <h3 class="mb-3 mt-0 text-base font-semibold">Aangemaakt</h3>

        <p v-if="!invoices.length" class="text-slate-500">Nog geen facturen.</p>

        <div v-for="invoice in invoices" :key="invoice.id" class="mb-4 last:mb-0">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-200 pb-1">
                <strong>{{ invoice.number }}</strong>
                <span class="text-slate-500">{{ nlDate(invoice.issued_on) }}</span>
                <strong>{{ euro(invoice.gross_cents) }}</strong>
                <span class="text-sm">
                    <a :href="`/beheer/${tenant.id}/facturen/${invoice.id}/pdf`" class="text-blue-700 underline">pdf</a>
                    &middot;
                    <a :href="`/beheer/${tenant.id}/facturen/${invoice.id}/xml`" class="text-blue-700 underline">xml</a>
                    &middot;
                    <span v-if="invoice.mailed_at" class="text-slate-500">
                        verstuurd {{ nlDate(invoice.mailed_at) }} {{ nlTime(invoice.mailed_at) }}
                    </span>
                    <button v-else type="button" :disabled="mailing === invoice.id" @click="mail(invoice)"
                        class="text-blue-700 underline disabled:opacity-60">versturen</button>
                </span>
            </div>

            <div v-for="line in invoice.lines" :key="line.id" class="flex justify-between py-1 text-sm text-slate-500">
                <span>{{ line.description }}</span>
                <span>{{ euro(line.amount_cents) }}</span>
            </div>

            <p v-if="invoice.mail_error" class="py-1 text-sm font-semibold text-red-700">
                Versturen mislukt: {{ invoice.mail_error }}
            </p>
        </div>
    </section>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { nlDate, nlTime } from '@/Utilities/Utilities'
import { euro } from '@/Components/Landlord/money.js'

const props = defineProps({
    tenant: { type: Object, required: true },
    invoices: { type: Array, required: true },
    preview: { type: Object, required: true },
    is_due: { type: Boolean, required: true },
    next_period_starts_on: { type: String, required: true },
})

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
</script>
