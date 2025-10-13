<template>
    <div class="max-w-5xl mx-auto py-6">
        <div class="mb-8">
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-slate-100">Bewerk Klant</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">Pas de gegevens van de klant aan.</p>
        </div>

        <form @submit.prevent="submit" class="space-y-10">
            <section
                class="bg-white dark:bg-slate-900 rounded-lg border border-gray-200 dark:border-slate-800 shadow-sm">
                <div class="px-6 py-5 border-b border-gray-200 dark:border-slate-800">
                    <h2 class="text-sm font-medium text-gray-900 dark:text-slate-100">Algemene Informatie</h2>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <TextInput v-model="form.name" label="Naam" required :has-error="!!form.errors.name" :error-message="form.errors.name" />
                    <TextInput v-model="form.contactname" label="Contactpersoon" :has-error="!!form.errors.contactname" :error-message="form.errors.contactname" />
                    <TextInput v-model="form.email" label="E-mail" type="email" :has-error="!!form.errors.email" :error-message="form.errors.email" />
                    <TextInput v-model="form.phone" label="Telefoon" :has-error="!!form.errors.phone" :error-message="form.errors.phone" />
                    <TextInput v-model="form.mobile" label="Mobiel" :has-error="!!form.errors.mobile" :error-message="form.errors.mobile" />
                    <TextInput v-model="form.website" label="Website" :has-error="!!form.errors.website" :error-message="form.errors.website" />
                </div>
            </section>

            <section
                class="bg-white dark:bg-slate-900 rounded-lg border border-gray-200 dark:border-slate-800 shadow-sm">
                <div class="px-6 py-5 border-b border-gray-200 dark:border-slate-800">
                    <h2 class="text-sm font-medium text-gray-900 dark:text-slate-100">Bezoekadres</h2>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <TextInput v-model="form.address" label="Adres" :has-error="!!form.errors.address" :error-message="form.errors.address" />
                    <TextInput v-model="form.postal_code" label="Postcode" :has-error="!!form.errors.postal_code" :error-message="form.errors.postal_code" />
                    <TextInput v-model="form.city" label="Plaats" :has-error="!!form.errors.city" :error-message="form.errors.city" />
                    <TextInput v-model="form.country" label="Land" :has-error="!!form.errors.country" :error-message="form.errors.country" />
                </div>
            </section>

            <section
                class="bg-white dark:bg-slate-900 rounded-lg border border-gray-200 dark:border-slate-800 shadow-sm">
                <div class="px-6 py-5 border-b border-gray-200 dark:border-slate-800">
                    <h2 class="text-sm font-medium text-gray-900 dark:text-slate-100">Postadres</h2>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <TextInput v-model="form.postal_address" label="Postbus/adres" :has-error="!!form.errors.postal_address" :error-message="form.errors.postal_address" />
                    <TextInput v-model="form.postal_postal_code" label="Postcode" :has-error="!!form.errors.postal_postal_code" :error-message="form.errors.postal_postal_code" />
                    <TextInput v-model="form.postal_city" label="Plaats" :has-error="!!form.errors.postal_city" :error-message="form.errors.postal_city" />
                    <TextInput v-model="form.postal_country" label="Land" :has-error="!!form.errors.postal_country" :error-message="form.errors.postal_country" />
                </div>
            </section>

            <section
                class="bg-white dark:bg-slate-900 rounded-lg border border-gray-200 dark:border-slate-800 shadow-sm">
                <div class="px-6 py-5 border-b border-gray-200 dark:border-slate-800">
                    <h2 class="text-sm font-medium text-gray-900 dark:text-slate-100">Financiële Informatie</h2>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <TextInput v-model="form.invoice_email" label="Factuur e-mail" type="email" :has-error="!!form.errors.invoice_email" :error-message="form.errors.invoice_email" />
                    <TextInput v-model="form.quotes_email" label="Offerte e-mail" type="email" :has-error="!!form.errors.quotes_email" :error-message="form.errors.quotes_email" />
                    <TextInput v-model="form.iban" label="IBAN" :has-error="!!form.errors.iban" :error-message="form.errors.iban" />
                    <TextInput v-model="form.vat_number" label="BTW-nummer" :has-error="!!form.errors.vat_number" :error-message="form.errors.vat_number" />
                    <TextInput v-model="form.chamber_of_commerce_number" label="KvK-nummer" :has-error="!!form.errors.chamber_of_commerce_number" :error-message="form.errors.chamber_of_commerce_number" />
                    <ComboBox :options="allCustomers" v-model="form.billing_customer_id" label="Factuurklant"
                        placeholder="Kies naar welke klant de factuur moet" :has-error="!!form.errors.billing_customer_id" :error-message="form.errors.billing_customer_id" />
                </div>
            </section>

            <div class="flex items-center gap-4">
                <button type="submit" :disabled="form.processing"
                    class="inline-flex items-center justify-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800 transition ease-in-out duration-150 disabled:opacity-25">
                    <svg v-if="form.processing" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white"
                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" class="opacity-25" />
                        <path d="M4 12a8 8 0 018-8" class="opacity-75" />
                    </svg>
                    <span>Opslaan</span>
                </button>
                <Link :href="`/customers/${customer.id}`"
                    class="text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-300">
                Annuleren</Link>
            </div>
        </form>
    </div>
</template>

<script setup>
import { useForm, Link } from '@inertiajs/vue3'
import TextInput from '@/Components/UI/TextInput.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'

const props = defineProps({
    customer: { type: Object, required: true },
    allCustomers: { type: Array, default: () => [] }
})

const form = useForm({
    name: props.customer.name,
    contactname: props.customer.contactname,
    email: props.customer.email,
    phone: props.customer.phone,
    mobile: props.customer.mobile,
    website: props.customer.website,
    address: props.customer.address,
    postal_code: props.customer.postal_code,
    city: props.customer.city,
    country: props.customer.country,
    postal_address: props.customer.postal_address,
    postal_postal_code: props.customer.postal_postal_code,
    postal_city: props.customer.postal_city,
    postal_country: props.customer.postal_country,
    invoice_email: props.customer.invoice_email,
    quotes_email: props.customer.quotes_email,
    iban: props.customer.iban,
    vat_number: props.customer.vat_number,
    chamber_of_commerce_number: props.customer.chamber_of_commerce_number,
    billing_customer_id: props.customer.billing_customer_id,
});

const submit = () => {
    form.put(`/customers/${props.customer.id}`)
}
</script>
