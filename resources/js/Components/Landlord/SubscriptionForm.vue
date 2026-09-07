<template>
    <PanelSection title="Abonnement">

        <form @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block font-semibold">Startdatum abonnement</label>
                    <input v-model="form.subscription_started_on" type="date" class="panel-field w-full">
                    <p v-if="!form.subscription_started_on" class="mt-1 text-sm font-semibold text-amber-700">
                        Zonder ingangsdatum wordt deze klant nooit gefactureerd.
                    </p>
                </div>
                <div>
                    <label class="mb-1 block font-semibold">Facturatie</label>
                    <select v-model="form.billing_period" class="panel-field w-full">
                        <option value="monthly">Per maand</option>
                        <option value="yearly">Per jaar (2% korting)</option>
                    </select>
                </div>
            </div>

            <label class="mb-1 mt-4 block font-semibold">Pakket</label>
            <select v-model="form.package_key" class="panel-field w-full">
                <option value="">— geen —</option>
                <option v-for="pack in packages" :key="pack.key" :value="pack.key">
                    {{ pack.name }} ({{ euro(pack.price_cents) }})
                </option>
            </select>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block font-semibold">Extra buitendienst</label>
                    <input v-model="form.extra_field_seats" type="number" min="0" class="panel-field w-full">
                </div>
                <div>
                    <label class="mb-1 block font-semibold">Extra binnendienst</label>
                    <input v-model="form.extra_office_seats" type="number" min="0" class="panel-field w-full">
                </div>
                <div>
                    <label class="mb-1 block font-semibold">Opslag (GB)</label>
                    <input v-model="form.storage_limit_gb" type="number" min="0" class="panel-field w-full">
                </div>
                <div>
                    <label class="mb-1 block font-semibold">AI-limiet per maand (€)</label>
                    <input v-model="form.ai_allowance_euro" type="number" step="0.01" min="0"
                        :placeholder="`${euro(ai.allowance_cents)} (standaard)`"
                        class="panel-field w-full">
                    <p class="mt-1 text-sm text-slate-500">
                        Deze maand verbruikt: {{ euro(ai.spent_cents) }} van {{ euro(ai.allowance_cents) }}.
                        <template v-if="ai.topup_cents > 0">Bijgekocht tegoed: {{ euro(ai.topup_cents) }}.</template>
                        Leeg laten volgt de standaard uit de catalogus.
                    </p>
                </div>
            </div>

            <div class="my-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                <div>Berekend: {{ euro(subscription.before_discount_cents) }}</div>
                <div v-if="subscription.discount_cents" class="text-red-700">
                    Korting: − {{ euro(subscription.discount_cents) }}
                </div>
                <div class="mt-1 text-lg font-bold">
                    Totaal: {{ euro(subscription.total_cents) }} per maand
                </div>
            </div>

            <label class="mb-1 block font-semibold">Korting</label>
            <div class="overflow-hidden rounded-md border border-slate-300">
                <label class="flex items-center gap-2.5 border-b border-slate-200 px-3 py-2">
                    <input v-model="form.discount_type" type="radio" value="none">
                    <span class="w-28 font-semibold">Geen korting</span>
                </label>
                <label class="flex items-center gap-2.5 border-b border-slate-200 px-3 py-2">
                    <input v-model="form.discount_type" type="radio" value="euro">
                    <span class="w-28 font-semibold">Vast bedrag</span>
                    <input v-model="form.discount_euro" type="number" step="0.01" min="0" placeholder="0,00"
                        class="w-28 rounded border border-slate-300 px-2 py-1">
                    <span class="text-slate-500">€ per maand</span>
                </label>
                <label class="flex items-center gap-2.5 px-3 py-2">
                    <input v-model="form.discount_type" type="radio" value="percent">
                    <span class="w-28 font-semibold">Percentage</span>
                    <input v-model="form.discount_percent" type="number" min="0" max="100" placeholder="0"
                        class="w-28 rounded border border-slate-300 px-2 py-1">
                    <span class="text-slate-500">%</span>
                </label>
            </div>

            <label for="ends-on" class="mb-1 mt-4 block font-semibold">
                Opgezegd per
                <span class="font-normal text-slate-500">(leeg = loopt door)</span>
            </label>
            <input id="ends-on" v-model="form.subscription_ends_on" type="date" class="panel-field w-full">
            <p v-if="form.errors.subscription_ends_on" class="mt-1 text-sm font-semibold text-red-700">
                {{ form.errors.subscription_ends_on }}
            </p>
            <p class="mt-1 text-sm text-slate-500">
                De laatste dag waarop het abonnement loopt. Er wordt tot en met die dag gerekend;
                is de maand al gefactureerd, dan komt het te veel betaalde als tegoed terug.
            </p>

            <label for="package-price" class="mb-1 mt-4 block font-semibold">
                Vaste pakketprijs (€)
                <span class="font-normal text-slate-500">(leeg = prijs uit de catalogus)</span>
            </label>
            <input id="package-price" v-model="form.price_override_euro" type="number" step="0.01" min="0"
                class="panel-field w-full">
            <p class="mt-1 text-sm text-slate-500">
                Geldt voor het pakket. Plekken, modules en extra opslag komen daar bovenop; voor een
                module spreek je hierboven een eigen prijs af.
            </p>

            <label class="mb-1 mt-4 block font-semibold">Factuurgegevens</label>
            <div class="grid gap-3 sm:grid-cols-2">
                <input v-model="form.invoice_address" type="text" placeholder="Straat en nummer" class="panel-field">
                <input v-model="form.invoice_email" type="email" placeholder="Factuur-e-mail" class="panel-field">
                <input v-model="form.invoice_postcode" type="text" placeholder="Postcode" class="panel-field">
                <input v-model="form.invoice_city" type="text" placeholder="Plaats" class="panel-field">
                <input v-model="form.vat_number" type="text" placeholder="BTW-nummer" class="panel-field">
                <input v-model="form.coc_number" type="text" placeholder="KvK-nummer" class="panel-field">
            </div>

            <label class="mb-1 mt-4 block font-semibold">Betaling</label>
            <div class="overflow-hidden rounded-md border border-slate-300">
                <label class="flex items-center gap-2.5 border-b border-slate-200 px-3 py-2">
                    <input v-model="form.payment_method" type="radio" value="transfer">
                    <span class="w-28 font-semibold">Overboeking</span>
                    <span class="text-slate-500">de klant maakt zelf over</span>
                </label>
                <label class="flex items-center gap-2.5 px-3 py-2">
                    <input v-model="form.payment_method" type="radio" value="direct_debit">
                    <span class="w-28 font-semibold">Incasso</span>
                    <span class="text-slate-500">automatisch afschrijven met machtiging</span>
                </label>
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <input v-model="form.iban" type="text" placeholder="IBAN" class="panel-field">
                <input v-model="form.account_holder" type="text" placeholder="Naam rekeninghouder" class="panel-field">
                <input v-model="form.mandate_reference" type="text" maxlength="35" placeholder="Machtigingskenmerk" class="panel-field">
                <input v-model="form.mandate_signed_on" type="date" class="panel-field">
            </div>
            <p v-if="form.errors.iban" class="mt-1 font-semibold text-red-700">{{ form.errors.iban }}</p>

            <label class="mb-1 mt-4 block font-semibold">Modules</label>
            <p class="mb-1 text-sm text-slate-500">
                Het veld achter een module is een eigen prijsafspraak; leeg is de normale prijs.
            </p>
            <div v-for="module in modules" :key="module.key" class="flex items-center gap-2 py-1">
                <label class="flex flex-1 items-center gap-2">
                    <input v-model="form.modules" type="checkbox" :value="module.key">
                    {{ module.name }}
                    <span v-if="module.price_cents" class="text-slate-500">{{ euro(module.price_cents) }}</span>
                </label>

                <input v-if="form.modules.includes(module.key)" v-model="form.module_prices[module.key]"
                    type="number" step="0.01" min="0" :placeholder="euroInput(module.price_cents)"
                    :aria-label="`Eigen prijs voor ${module.name}`" class="panel-field-narrow w-24">
            </div>
            <p v-if="form.errors.module_prices" class="mt-1 text-sm font-semibold text-red-700">
                {{ form.errors.module_prices }}
            </p>

            <p class="mt-4 flex items-center gap-4">
                <button type="submit" :disabled="form.processing"
                    class="panel-button">
                    {{ form.processing ? 'Bezig…' : 'Opslaan' }}
                </button>
                <Link href="/beheer" class="text-blue-700 underline">annuleren</Link>
            </p>
        </form>
    </PanelSection>
</template>

<script setup>
import PanelSection from '@/Components/Landlord/PanelSection.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { euro, euroInput } from './money.js'

const props = defineProps({
    tenant: { type: Object, required: true },
    packages: { type: Array, required: true },
    modules: { type: Array, required: true },
    ai: { type: Object, required: true },
    subscription: { type: Object, required: true },
})

/** Welke van de drie kortingen aanstaat volgt uit wat er is ingevuld. */
const discountType = props.tenant.discount_percent
    ? 'percent'
    : (props.tenant.discount_cents ? 'euro' : 'none')

const form = useForm({
    subscription_started_on: props.tenant.subscription_started_on,
    subscription_ends_on: props.tenant.subscription_ends_on ?? '',
    billing_period: props.tenant.billing_period === 'yearly' ? 'yearly' : 'monthly',
    package_key: props.tenant.package_key ?? '',
    extra_field_seats: props.tenant.extra_field_seats,
    extra_office_seats: props.tenant.extra_office_seats,
    storage_limit_gb: props.tenant.storage_limit_gb,
    ai_allowance_euro: props.ai.is_default ? '' : euroInput(props.ai.allowance_cents),
    discount_type: discountType,
    discount_euro: euroInput(props.tenant.discount_cents),
    discount_percent: props.tenant.discount_percent || '',
    price_override_euro: euroInput(props.tenant.price_override_cents),
    invoice_address: props.tenant.invoice_address ?? '',
    invoice_email: props.tenant.invoice_email ?? '',
    invoice_postcode: props.tenant.invoice_postcode ?? '',
    invoice_city: props.tenant.invoice_city ?? '',
    vat_number: props.tenant.vat_number ?? '',
    coc_number: props.tenant.coc_number ?? '',
    payment_method: props.tenant.payment_method === 'direct_debit' ? 'direct_debit' : 'transfer',
    iban: props.tenant.iban ?? '',
    account_holder: props.tenant.account_holder ?? '',
    mandate_reference: props.tenant.mandate_reference ?? '',
    mandate_signed_on: props.tenant.mandate_signed_on ?? '',
    modules: [...(props.tenant.modules ?? [])],
    /** Centen uit de database worden euro's in het veld, net als de andere bedragen. */
    module_prices: Object.fromEntries(
        Object.entries(props.tenant.module_prices ?? {}).map(([key, cents]) => [key, euroInput(cents)]),
    ),
})

const submit = () => form.put(`/beheer/${props.tenant.id}`, { preserveScroll: true })
</script>
