<template>
    <div class="space-y-6">
        <div
            class="p-4 bg-white rounded-md dark:bg-slate-800 shadow-sm dark:shadow-none ring-1 ring-gray-900/5 dark:ring-slate-800 dark:text-white">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Technisch beheer</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">Beheerfuncties voor technisch gebruik</p>
        </div>


        <form @submit.prevent="saveIntegrations" class="space-y-6">
            <div class="p-6 bg-white rounded-md dark:bg-slate-800 shadow-sm dark:shadow-none ring-1 ring-gray-900/5 dark:ring-slate-800">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Uitgaande mail</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-slate-400 mb-4">
                    Waarmee dit bedrijf zijn e-mail verstuurt. Deze gegevens gelden alleen voor dit bedrijf.
                </p>

                <div class="max-w-xl space-y-5">
                    <div class="rounded-md ring-1 ring-gray-300 dark:ring-slate-600 overflow-hidden">
                        <label v-for="option in TRANSPORTS" :key="option.value"
                            class="flex items-start gap-3 px-4 py-3 cursor-pointer border-b border-gray-200 dark:border-slate-700 last:border-b-0"
                            :class="integrations.mail_transport === option.value ? 'bg-blue-50 dark:bg-slate-700/50' : ''">
                            <input type="radio" v-model="integrations.mail_transport" :value="option.value"
                                class="mt-0.5 text-lavoro-blue focus:ring-lavoro-blue" />
                            <span>
                                <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ option.label }}</span>
                                <span class="block text-xs text-gray-500 dark:text-slate-400">{{ option.hint }}</span>
                            </span>
                        </label>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <TextInput v-model="integrations.mail_from_address" label="Afzenderadres"
                            placeholder="info@jouwbedrijf.nl" :has-error="!!integrations.errors.mail_from_address"
                            :error-message="integrations.errors.mail_from_address || ''" />
                        <TextInput v-model="integrations.mail_from_name" label="Afzendernaam"
                            placeholder="Jouw Bedrijf" :has-error="!!integrations.errors.mail_from_name"
                            :error-message="integrations.errors.mail_from_name || ''" />
                    </div>

                    <div v-if="integrations.mail_transport === 'graph'" class="space-y-4">
                        <p class="text-xs text-gray-500 dark:text-slate-400">
                            Uit de app-registratie in Azure. De app heeft de rechten Mail.Send en Mail.ReadWrite nodig.
                        </p>
                        <div class="grid grid-cols-2 gap-4">
                            <TextInput v-model="integrations.graph_azure_tenant_id" label="Directory (tenant) ID"
                                :has-error="!!integrations.errors.graph_azure_tenant_id"
                                :error-message="integrations.errors.graph_azure_tenant_id || ''" />
                            <TextInput v-model="integrations.graph_client_id" label="Application (client) ID"
                                :has-error="!!integrations.errors.graph_client_id"
                                :error-message="integrations.errors.graph_client_id || ''" />
                        </div>
                        <SecretField v-model="integrations.graph_client_secret" label="Clientgeheim"
                            :stored="storedSecrets.graph_client_secret"
                            :error="integrations.errors.graph_client_secret"
                            @forget="forgetSecret('graph_client_secret')" />
                        <div>
                            <TextInput v-model="integrations.graph_user_id" label="Mailbox"
                                placeholder="info@jouwbedrijf.nl" :has-error="!!integrations.errors.graph_user_id"
                                :error-message="integrations.errors.graph_user_id || ''" />
                            <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                Het postvak waaruit verstuurd wordt. Moet in dezelfde Azure-omgeving bestaan.
                            </p>
                        </div>
                    </div>

                    <div v-if="integrations.mail_transport === 'smtp'" class="space-y-4">
                        <div class="grid grid-cols-3 gap-4">
                            <div class="col-span-2">
                                <TextInput v-model="integrations.mail_smtp_host" label="Server"
                                    placeholder="mail.jouwbedrijf.nl" :has-error="!!integrations.errors.mail_smtp_host"
                                    :error-message="integrations.errors.mail_smtp_host || ''" />
                            </div>
                            <TextInput v-model="integrations.mail_smtp_port" label="Poort" type="number"
                                placeholder="587" :has-error="!!integrations.errors.mail_smtp_port"
                                :error-message="integrations.errors.mail_smtp_port || ''" />
                        </div>
                        <div>
                            <ComboBox label="Beveiliging" :options="SCHEMES" v-model="integrations.mail_smtp_scheme"
                                :initial-id="integrations.mail_smtp_scheme" emit-value
                                placeholder="Afleiden uit de poort" />
                            <p v-if="integrations.errors.mail_smtp_scheme" class="mt-1 text-sm text-red-600">
                                {{ integrations.errors.mail_smtp_scheme }}
                            </p>
                        </div>
                        <TextInput v-model="integrations.mail_smtp_username" label="Gebruikersnaam"
                            :has-error="!!integrations.errors.mail_smtp_username"
                            :error-message="integrations.errors.mail_smtp_username || ''" />
                        <SecretField v-model="integrations.mail_smtp_password" label="Wachtwoord"
                            :stored="storedSecrets.mail_smtp_password"
                            :error="integrations.errors.mail_smtp_password"
                            @forget="forgetSecret('mail_smtp_password')" />
                    </div>
                </div>
            </div>

            <div class="p-6 bg-white rounded-md dark:bg-slate-800 shadow-sm dark:shadow-none ring-1 ring-gray-900/5 dark:ring-slate-800">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">SnelStart</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-slate-400 mb-4">
                    Uit SnelStart, onder Instellingen &rarr; Koppelingen. Zonder deze sleutels blijft de
                    SnelStart-knop op de werkbon uit.
                </p>
                <div class="max-w-xl space-y-4">
                    <SecretField v-model="integrations.snelstart_client_key" label="Clientsleutel"
                        :stored="storedSecrets.snelstart_client_key"
                        :error="integrations.errors.snelstart_client_key"
                        @forget="forgetSecret('snelstart_client_key')" />
                    <SecretField v-model="integrations.snelstart_subscription_key" label="Abonnementssleutel"
                        :stored="storedSecrets.snelstart_subscription_key"
                        :error="integrations.errors.snelstart_subscription_key"
                        @forget="forgetSecret('snelstart_subscription_key')" />
                </div>
            </div>

            <div>
                <button type="submit" :disabled="integrations.processing"
                    class="inline-flex items-center rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">
                    Opslaan
                </button>
            </div>
        </form>

        <div class="p-6 bg-white rounded-md dark:bg-slate-800 shadow-sm dark:shadow-none ring-1 ring-gray-900/5 dark:ring-slate-800">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Test e-mail versturen</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-slate-400 mb-4">
                Stuur een test e-mail om de mailconfiguratie te controleren.
            </p>

            <form @submit.prevent="submit" class="space-y-4 max-w-md">
                <div>
                    <label for="email"
                        class="block text-sm font-medium text-gray-900 dark:text-white">E-mailadres</label>
                    <div class="mt-1">
                        <input id="email" v-model="form.email" type="email" autocomplete="off"
                            placeholder="naam@voorbeeld.nl"
                            class="block w-full rounded-md border-0 py-1.5 pl-2 text-gray-900 dark:text-white dark:bg-slate-900 ring-1 ring-inset sm:text-sm sm:leading-6"
                            :class="form.errors.email
                                ? 'ring-red-300 focus:ring-red-500 placeholder:text-red-300'
                                : 'ring-gray-300 dark:ring-slate-500 focus:ring-indigo-600 placeholder:text-gray-400 dark:placeholder:text-gray-600 focus:ring-2 focus:ring-inset'" />
                        <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                    </div>
                </div>

                <button type="submit" :disabled="form.processing"
                    class="inline-flex items-center gap-2 rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50">
                    <span v-if="form.processing">Versturen...</span>
                    <span v-else>Test e-mail versturen</span>
                </button>
            </form>

            <div v-if="lastError"
                class="mt-4 rounded-md bg-red-50 dark:bg-red-900/20 p-4 ring-1 ring-red-300 dark:ring-red-700">
                <p class="text-sm font-semibold text-red-800 dark:text-red-400">Foutmelding:</p>
                <p class="mt-1 text-sm text-red-700 dark:text-red-300 break-all whitespace-pre-wrap">{{ lastError }}</p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import ComboBox from '@/Components/UI/ComboBox.vue'
import SecretField from '@/Components/UI/SecretField.vue'
import TextInput from '@/Components/UI/TextInput.vue'

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    storedSecrets: { type: Object, default: () => ({}) },
})

const TRANSPORTS = [
    {
        value: 'graph',
        label: 'Microsoft 365',
        hint: 'Lavoro logt in op jullie eigen postvak en verstuurt daaruit.',
    },
    {
        value: 'smtp',
        label: 'Eigen mailserver',
        hint: 'Lavoro levert de post af bij een SMTP-server die jullie opgeven.',
    },
]

const SCHEMES = [
    { id: '', name: 'Afleiden uit de poort' },
    { id: 'smtps', name: 'Direct TLS (meestal poort 465)' },
    { id: 'smtp', name: 'STARTTLS (meestal poort 587)' },
]

const integrations = useForm({
    mail_transport: props.settings.mail_transport || 'graph',
    mail_from_address: props.settings.mail_from_address || '',
    mail_from_name: props.settings.mail_from_name || '',
    graph_azure_tenant_id: props.settings.graph_azure_tenant_id || '',
    graph_client_id: props.settings.graph_client_id || '',
    graph_client_secret: '',
    graph_user_id: props.settings.graph_user_id || '',
    mail_smtp_host: props.settings.mail_smtp_host || '',
    mail_smtp_port: props.settings.mail_smtp_port || '',
    mail_smtp_scheme: props.settings.mail_smtp_scheme || '',
    mail_smtp_username: props.settings.mail_smtp_username || '',
    mail_smtp_password: '',
    snelstart_client_key: '',
    snelstart_subscription_key: '',
})

const saveIntegrations = () => integrations.put('/technical-management/integrations', { preserveScroll: true })

const forgetSecret = (key) => {
    integrations.delete(`/technical-management/integrations/secrets/${key}`, { preserveScroll: true })
}

const page = usePage()
const form = useForm({ email: '' })
const lastError = ref(null)

watch(() => page.props.flash.error, (val) => {
    if (val) lastError.value = val
})

watch(() => page.props.flash.success, (val) => {
    if (val) lastError.value = null
})

const submit = () => {
    lastError.value = null
    form.post('/technical-management/test-mail')
}
</script>
