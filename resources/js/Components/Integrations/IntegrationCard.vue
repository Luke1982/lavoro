<template>
    <div class="p-6 bg-white rounded-md dark:bg-slate-800 shadow-sm dark:shadow-none ring-1 ring-gray-900/5 dark:ring-slate-700">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ connector.label }}</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-slate-400">{{ connector.description }}</p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <span v-for="capability in connector.capabilities" :key="capability.key"
                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-gray-300 text-gray-600 dark:ring-slate-600 dark:text-slate-300">
                        {{ capability.label }}
                    </span>
                    <span v-for="form in connector.document_forms" :key="form.id"
                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-gray-300 text-gray-600 dark:ring-slate-600 dark:text-slate-300">
                        Werkbon als {{ form.name.toLowerCase() }}
                    </span>
                </div>
            </div>
            <div v-if="connection" class="flex items-center gap-2 shrink-0">
                <span class="text-sm text-gray-500 dark:text-slate-400">Actief</span>
                <SwitchComponent v-model="form.is_active" title="Koppeling actief" />
            </div>
            <a v-else-if="connector.oauth && connector.oauth_ready" :href="oauthUrl"
                class="shrink-0 inline-flex items-center rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                Koppelen met {{ connector.label }}
            </a>
            <button v-else-if="!connector.oauth && !opening" type="button" @click="opening = true"
                class="shrink-0 inline-flex items-center rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                Koppelen
            </button>
        </div>

        <p v-if="connector.oauth && !connector.oauth_ready" class="mt-4 text-sm text-gray-500 dark:text-slate-400">
            Koppelen met {{ connector.label }} kan zodra MajorLabel de app voor {{ connector.label }} heeft geregistreerd.
        </p>
        <p v-else-if="connection && connector.oauth && !connection.signed_in"
            class="mt-4 rounded-md bg-amber-50 dark:bg-amber-900/20 p-3 text-sm text-amber-800 dark:text-amber-300 ring-1 ring-amber-200 dark:ring-amber-800">
            Het koppelen is niet afgemaakt. <a :href="oauthUrl" class="font-semibold underline">Koppel opnieuw met {{ connector.label }}</a>.
        </p>

        <div v-if="connection" class="mt-4 text-sm">
            <p v-if="connection.last_error"
                class="rounded-md bg-red-50 dark:bg-red-900/20 p-3 text-red-700 dark:text-red-300 ring-1 ring-red-200 dark:ring-red-800">
                <span class="font-semibold">Laatste fout ({{ when(connection.last_error_at) }}):</span>
                {{ connection.last_error }}
            </p>
            <p v-else-if="connection.last_synced_at" class="text-gray-500 dark:text-slate-400">
                Laatst gesynchroniseerd: {{ when(connection.last_synced_at) }}.
            </p>
            <p v-if="linkedText" class="mt-1 text-gray-500 dark:text-slate-400">{{ linkedText }}</p>
        </div>

        <form v-if="connection || opening" class="mt-6 space-y-8" @submit.prevent="save">
            <section v-if="connector.credential_fields.length">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Inloggegevens</h3>
                <div class="max-w-xl space-y-4">
                    <template v-for="field in connector.credential_fields" :key="field.key">
                        <SecretField v-if="field.secret" v-model="form.credentials[field.key]" :label="field.label"
                            :stored="stored(field.key)" :error="form.errors['credentials.' + field.key]"
                            @forget="forget(field.key)" />
                        <TextInput v-else v-model="form.credentials[field.key]" :label="field.label"
                            :has-error="!!form.errors['credentials.' + field.key]"
                            :error-message="form.errors['credentials.' + field.key] || ''" />
                        <p v-if="field.help" class="-mt-3 text-xs text-gray-500 dark:text-slate-400">{{ field.help }}</p>
                    </template>
                </div>
            </section>

            <section v-if="connector.option_fields.length">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Instellingen</h3>
                <div class="max-w-xl space-y-4">
                    <template v-for="field in connector.option_fields" :key="field.key">
                        <TextInput v-model="form.options[field.key]" :label="field.label"
                            :placeholder="field.default || ''"
                            :has-error="!!form.errors['options.' + field.key]"
                            :error-message="form.errors['options.' + field.key] || ''" />
                        <p v-if="field.help" class="-mt-3 text-xs text-gray-500 dark:text-slate-400">{{ field.help }}</p>
                    </template>
                </div>
            </section>

            <section v-if="connection">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">Wat gaat er over en weer</h3>
                <p class="text-xs text-gray-500 dark:text-slate-400 mb-4">
                    Wat maar aan één kant veranderde, gaat altijd mee. De keuze "wie wint" geldt alleen als een
                    record sinds de vorige keer aan beide kanten is gewijzigd.
                </p>

                <div class="divide-y divide-gray-200 dark:divide-slate-700 rounded-md ring-1 ring-gray-200 dark:ring-slate-700">
                    <div v-for="area in exchangeable" :key="area.key" class="p-4 space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ area.label }}</p>
                            <div class="flex items-center gap-3">
                                <Link v-if="area.key === 'ledger_accounts' && connection.is_active && (connection.settings.areas.ledger_accounts.read || connection.settings.areas.ledger_accounts.write)"
                                    href="/integrations/ledger-accounts"
                                    class="text-xs font-semibold text-lavoro-blue dark:text-blue-400 hover:underline">
                                    Bekijken
                                </Link>
                                <button v-if="connection.settings.areas[area.key].read && canPull" type="button"
                                    :disabled="pullForm.processing" @click="pull(area.key)"
                                    class="text-xs font-semibold text-lavoro-blue dark:text-blue-400 hover:underline disabled:opacity-50">
                                    {{ pulling === area.key ? 'Bezig...' : 'Nu ophalen' }}
                                </button>
                            </div>
                        </div>
                        <Toggle v-if="can(area.key + '.read')" v-model="form.areas[area.key].read"
                            :label="'Ophalen uit ' + connector.label"
                            :hint="connector.change_detection[area.key] ? 'Wijzigingen: ' + connector.change_detection[area.key] + '.' : ''"
                            :error="form.errors['areas.' + area.key + '.read']" />
                        <Toggle v-if="can(area.key + '.write')" v-model="form.areas[area.key].write"
                            :label="'Versturen naar ' + connector.label"
                            :hint="'Nieuwe en gewijzigde ' + area.label.toLowerCase() + ' in Lavoro gaan meteen mee.'"
                            :error="form.errors['areas.' + area.key + '.write']" />
                        <Toggle v-if="form.areas[area.key].read" :model-value="form.areas[area.key].leading === 'lavoro'"
                            @update:model-value="(lavoro) => { form.areas[area.key].leading = lavoro ? 'lavoro' : 'external' }"
                            label="Lavoro wint bij een wijziging aan beide kanten"
                            :hint="form.areas[area.key].leading === 'lavoro'
                                ? 'Wat in Lavoro is aangepast blijft staan' + (form.areas[area.key].write ? ' en gaat naar ' + connector.label + '.' : '.')
                                : 'De versie van ' + connector.label + ' overschrijft wat in Lavoro anders is ingevuld.'" />
                    </div>

                    <div v-if="connector.document_forms.length" class="p-4 space-y-3">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Werkbonnen</p>
                        <p class="text-xs text-gray-500 dark:text-slate-400">
                            Met de knop op een gesloten werkbon. Kies wat de werkbon in {{ connector.label }} wordt;
                            klik nog eens op je keuze om werkbonnen niet meer te versturen.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="option in connector.document_forms" :key="option.id" type="button"
                                @click="form.areas.service_orders.form = form.areas.service_orders.form === option.id ? null : option.id"
                                :class="[
                                    'px-3 py-1.5 rounded-md text-sm font-medium border transition-colors',
                                    form.areas.service_orders.form === option.id
                                        ? 'bg-lavoro-blue border-lavoro-blue text-white'
                                        : 'bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800/50',
                                ]">
                                {{ option.name }}
                            </button>
                        </div>
                        <p v-if="form.errors['areas.service_orders.form']" class="text-xs text-red-600 dark:text-red-400">
                            {{ form.errors['areas.service_orders.form'] }}
                        </p>
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" :disabled="form.processing"
                    class="inline-flex items-center rounded-md bg-lavoro-blue px-4 py-2 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50">
                    {{ connection ? 'Opslaan' : 'Koppelen' }}
                </button>
                <button v-if="!connection" type="button" @click="opening = false"
                    class="text-sm text-gray-600 dark:text-slate-400 hover:underline">
                    Annuleren
                </button>
                <template v-if="connection">
                    <a v-if="connector.oauth && connector.oauth_ready" :href="oauthUrl"
                        class="inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-700">
                        Opnieuw koppelen
                    </a>
                    <button type="button" :disabled="testForm.processing" @click="test"
                        class="inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-700 disabled:opacity-50">
                        {{ testForm.processing ? 'Bezig...' : 'Verbinding testen' }}
                    </button>
                    <button type="button" @click="remove"
                        class="ml-auto text-sm text-red-600 dark:text-red-400 hover:underline">
                        Koppeling verwijderen
                    </button>
                </template>
            </div>
        </form>

        <section v-if="connection?.runs.length" class="mt-8">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Laatste synchronisaties</h3>
            <ul class="divide-y divide-gray-100 dark:divide-slate-700 text-sm">
                <li v-for="run in connection.runs" :key="run.id" class="py-2 flex gap-3">
                    <span class="w-32 shrink-0 text-gray-500 dark:text-slate-400">{{ when(run.started_at) }}</span>
                    <span class="w-40 shrink-0 text-gray-700 dark:text-slate-300">
                        {{ run.area }} {{ run.direction === 'push' ? 'verstuurd' : 'opgehaald' }}
                    </span>
                    <span :class="run.status === 'failed' ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-slate-300'">
                        {{ run.status === 'running' ? 'bezig…' : run.summary }}
                        <span v-if="run.message" class="block text-xs text-gray-500 dark:text-slate-400">{{ run.message }}</span>
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import SecretField from '@/Components/UI/SecretField.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import Toggle from '@/Components/Integrations/IntegrationToggle.vue'
import { hasAnyPermission, nlDate, nlTime } from '@/Utilities/Utilities.js'

const props = defineProps({
    connector: { type: Object, required: true },
    connection: { type: Object, default: null },
    areas: { type: Array, default: () => [] },
})

const opening = ref(false)

const oauthUrl = computed(() => `/integrations/${props.connector.key}/oauth`)

const credentials = () => Object.fromEntries(props.connector.credential_fields.map((field) => {
    const stored = props.connection?.credentials.find((credential) => credential.key === field.key)
    return [field.key, stored?.value || '']
}))

const options = () => Object.fromEntries(props.connector.option_fields.map((field) => [
    field.key, props.connection?.settings.options[field.key] || '',
]))

const can = (capability) => props.connector.capabilities.some((item) => item.key === capability)
const synced = computed(() => props.areas.filter((area) => area.synced))
const exchangeable = computed(() => synced.value.filter((area) => can(area.key + '.read') || can(area.key + '.write')))

const areaSettings = () => {
    const current = props.connection?.settings.areas
    return {
        ...Object.fromEntries(synced.value.map((area) => [area.key, {
            read: current?.[area.key]?.read ?? false,
            write: current?.[area.key]?.write ?? false,
            leading: current?.[area.key]?.leading ?? 'external',
        }])),
        service_orders: { form: current?.service_orders.form ?? null },
    }
}

const form = useForm({
    connector: props.connector.key,
    is_active: props.connection?.is_active ?? true,
    credentials: credentials(),
    options: options(),
    areas: areaSettings(),
})

const stored = (key) => !!props.connection?.credentials.find((credential) => credential.key === key)?.stored
const canPull = computed(() => hasAnyPermission(['integration.pull', 'integration.manage']))

const linkedText = computed(() => {
    const linked = props.connection?.linked || {}
    const parts = props.areas
        .filter((area) => linked[area.key])
        .map((area) => linked[area.key] + ' ' + area.label.toLowerCase())
    return parts.length ? 'Gekoppeld: ' + parts.join(', ') + '.' : ''
})

const when = (moment) => (moment ? nlDate(moment) + ' ' + nlTime(moment) : '')

const resetSecrets = () => {
    props.connector.credential_fields
        .filter((field) => field.secret)
        .forEach((field) => { form.credentials[field.key] = '' })
}

/**
 * Connecting sends only what logs in: the switches are not on screen yet, and
 * sending them would replace the defaults with "everything off".
 */
const save = () => {
    if (props.connection) {
        form.transform((data) => data)
            .put(`/integrations/${props.connection.id}`, { preserveScroll: true, onSuccess: resetSecrets })
        return
    }
    form.transform(({ connector, credentials, options }) => ({ connector, credentials, options }))
        .post('/integrations', { preserveScroll: true, onSuccess: () => { opening.value = false; resetSecrets() } })
}

const testForm = useForm({})
const test = () => testForm.post(`/integrations/${props.connection.id}/test`, { preserveScroll: true })

const pullForm = useForm({})
const pulling = ref(null)
const pull = (area) => {
    pulling.value = area
    pullForm.post(`/integrations/${props.connection.id}/pull/${area}`, {
        preserveScroll: true,
        onFinish: () => { pulling.value = null },
    })
}

const forget = (key) => {
    router.delete(`/integrations/${props.connection.id}/credentials/${key}`, { preserveScroll: true })
}

const remove = () => {
    if (!confirm('De koppeling met ' + props.connector.label + ' verwijderen? Wat al is uitgewisseld blijft staan, '
        + 'in Lavoro en in ' + props.connector.label + '; alleen de koppeling tussen de twee verdwijnt.')) {
        return
    }
    router.delete(`/integrations/${props.connection.id}`, { preserveScroll: true })
}
</script>
