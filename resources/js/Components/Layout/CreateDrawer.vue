<template>
    <DrawerComponent v-model="open" :title="title" :subtitle="subtitle">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <!-- Werkbon: een klant is genoeg om er een te openen; de rest komt op de bon zelf. -->
            <template v-if="action?.id === 'nieuwe-werkbon'">
                <Field label="Klant">
                    <ComboBox v-model="form.customer_id" :options="customers" :searching="customersSearching"
                        has-external-searching placeholder="Zoek klant…" @change="searchCustomers" />
                    <FieldError :message="form.errors.customer_id" />
                </Field>
                <Field v-if="form.customer_id" label="Locatie">
                    <ComboBox v-model="form.location_id" :options="locations" placeholder="Optioneel" />
                    <FieldError :message="form.errors.location_id" />
                </Field>
            </template>

            <!-- Storing: waar hij op zit, wat er is, en hoe erg. -->
            <template v-else-if="action?.id === 'nieuwe-storing'">
                <Field label="Klant">
                    <ComboBox v-model="form.customer_id" :options="customers" :searching="customersSearching"
                        has-external-searching placeholder="Zoek klant…" @change="searchCustomers" />
                </Field>
                <Field label="Machine">
                    <AssetSelectMenu v-model="selectedAsset" :assets="assets" needs-box
                        :placeholder="form.customer_id ? 'Selecteer machine' : 'Kies eerst een klant'" />
                    <FieldError :message="form.errors.asset_id" />
                </Field>
                <Field label="Onderwerp">
                    <TextInput v-model="form.subject" :has-error="Boolean(form.errors.subject)"
                        :error-message="form.errors.subject" />
                </Field>
                <Field label="Omschrijving">
                    <textarea v-model="form.description" rows="4" :class="textareaClass"></textarea>
                    <FieldError :message="form.errors.description" />
                </Field>
                <Field label="Prioriteit">
                    <select v-model="form.priority" :class="selectClass">
                        <option v-for="option in priorities" :key="option" :value="option">{{ option }}</option>
                    </select>
                    <FieldError :message="form.errors.priority" />
                </Field>
            </template>

            <!-- Afspraak: wie, wat en wanneer. De rest regelt de planner. -->
            <template v-else-if="action?.id === 'nieuwe-afspraak'">
                <Field label="Soort afspraak">
                    <ComboBox v-model="form.event_type_id" :options="eventTypes" placeholder="Kies een soort" />
                    <FieldError :message="form.errors.event_type_id" />
                </Field>
                <Field label="Monteurs">
                    <ComboBox v-model="form.executing_user_ids" :options="users" multiple placeholder="Kies monteurs" />
                    <FieldError :message="form.errors.executing_user_ids" />
                </Field>
                <Field label="Datum">
                    <input v-model="form.date" type="date" :class="selectClass">
                </Field>
                <div class="grid grid-cols-2 gap-4 px-4 py-4 sm:px-6">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Van
                        <input v-model="form.start_time" type="time" :class="selectClass"></label>
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Tot
                        <input v-model="form.end_time" type="time" :class="selectClass"></label>
                </div>
                <FieldError class="px-4 pb-2 sm:px-6" :message="form.errors.start || form.errors.end" />
            </template>

            <!-- Klant: alleen de naam is verplicht; de rest mag later. -->
            <template v-else-if="action?.id === 'nieuwe-klant'">
                <Field label="Naam">
                    <TextInput v-model="form.name" :has-error="Boolean(form.errors.name)"
                        :error-message="form.errors.name" />
                </Field>
                <Field label="E-mail">
                    <TextInput v-model="form.email" type="email" :has-error="Boolean(form.errors.email)"
                        :error-message="form.errors.email" />
                </Field>
                <Field label="Telefoon">
                    <TextInput v-model="form.phone" :has-error="Boolean(form.errors.phone)"
                        :error-message="form.errors.phone" />
                </Field>
                <Field label="Adres">
                    <TextInput v-model="form.address" :has-error="Boolean(form.errors.address)"
                        :error-message="form.errors.address" />
                </Field>
                <div class="grid grid-cols-2 gap-4 px-4 py-4 sm:px-6">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Postcode
                        <TextInput v-model="form.postal_code" :has-error="Boolean(form.errors.postal_code)"
                            :error-message="form.errors.postal_code" /></label>
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Plaats
                        <TextInput v-model="form.city" /></label>
                </div>
            </template>
        </div>

        <template #footer>
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" @click="close"
                    class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" :disabled="saving" @click="submit(false)"
                    class="rounded-md bg-lavoro-blue px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                    {{ saving ? 'Bezig…' : 'Opslaan' }}
                </button>
                <!--
                    Alleen waar er iets te bezoeken valt: een afspraak staat in de
                    planner en heeft geen eigen pagina om heen te gaan.
                -->
                <button v-if="canVisit" type="button" :disabled="saving" @click="submit(true)"
                    class="rounded-md bg-lavoro-green px-4 py-2 text-sm font-medium text-slate-800 disabled:opacity-50">
                    Opslaan en direct bezoeken
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import AssetSelectMenu from '@/Components/UI/AssetSelectMenu.vue'
import Field from '@/Components/UI/DrawerField.vue'
import FieldError from '@/Components/UI/DrawerFieldError.vue'
import { useComboSearch } from '@/Composables/useComboSearch'
import { mapAssetForSelect } from '@/Utilities/Utilities'

/**
 * Snel iets aanmaken vanaf de knop onderin, zonder eerst naar een pagina te
 * hoeven. Elk soort heeft hier zijn eigen paar velden — net genoeg om het record
 * te laten bestaan, de rest gebeurt op het record zelf.
 *
 * De validatie blijft waar hij hoort: deze velden gaan naar dezelfde endpoints
 * als de gewone formulieren, en wat terugkomt aan fouten wordt hier alleen
 * getoond.
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    action: { type: Object, default: null },
})

const emit = defineEmits(['update:modelValue'])

const open = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
})

const page = usePage()

const priorities = ['Laag', 'Normaal', 'Hoog']

const inputClass = 'mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 '
    + 'dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100'
const textareaClass = inputClass
const selectClass = inputClass

/** Klanten en machines zijn er te veel van om in één keer mee te sturen; die zoekt de server. */
const { options: customers, searching: customersSearching, search: searchCustomers } = useComboSearch('customers', [], true)
/**
 * AssetSelectMenu zoekt zelf in wat het krijgt en werkt met het hele record, dus
 * de lijst komt hier binnen zoals de rest van de applicatie hem kent en het
 * formulier houdt er alleen het id van over.
 */
const assets = ref([])
const selectedAsset = ref(null)

const loadAssets = async () => {
    if (!form.customer_id) {
        assets.value = []

        return
    }

    const { data } = await axios.get('/combo/assets', { params: { customer_id: form.customer_id } })
    assets.value = data.map(mapAssetForSelect)
}

watch(selectedAsset, (asset) => { form.asset_id = asset?.id ?? null })

const locations = ref([])
const users = ref([])
const eventTypes = ref([])
const saving = ref(false)

const form = useForm({
    customer_id: null, location_id: null,
    asset_id: null, subject: '', description: '', priority: 'Normaal', status: 'Open',
    event_type_id: null, executing_user_ids: [], date: '', start_time: '08:00', end_time: '10:00',
    name: '', email: '', phone: '', address: '', postal_code: '', city: '',
})

const title = computed(() => 'Nieuwe ' + (props.action?.label ?? 'record').toLowerCase())

const subtitle = computed(() => ({
    'nieuwe-werkbon': 'Kies de klant waarvoor de werkbon is.',
    'nieuwe-storing': 'Leg vast wat er aan de hand is.',
    'nieuwe-afspraak': 'Zet hem meteen in de planning.',
    'nieuwe-klant': 'Alleen de naam is verplicht.',
}[props.action?.id] ?? ''))

/** Wat het formulier nodig heeft, wordt opgehaald zodra het opengaat en niet eerder. */
watch(() => [props.modelValue, props.action?.id], async ([isOpen, id]) => {
    if (!isOpen) return

    /**
     * Leeg beginnen, elke keer. Wie iets intikt en dan annuleert, wil dat niet
     * terugvinden zodra hij de la opnieuw opent — en al helemaal niet in een la
     * voor iets heel anders, want een paar velden delen ze.
     */
    form.reset()
    form.clearErrors()
    selectedAsset.value = null
    assets.value = []
    locations.value = []

    if (id === 'nieuwe-werkbon') customers.value = (await axios.get('/combo/customers')).data
    if (id === 'nieuwe-storing') assets.value = []
    if (id === 'nieuwe-afspraak') {
        form.date = form.date || new Date().toISOString().slice(0, 10)

        /**
         * Mag deze gebruiker de lijsten niet ophalen, dan blijft het veld leeg en
         * zegt de server bij het opslaan waarom. Een onafgevangen fout zou de la
         * halverwege laten staan zonder dat iemand weet wat er mis is.
         */
        try {
            const [types, people] = await Promise.all([
                axios.get('/combo/eventtypes'),
                axios.get('/combo/users'),
            ])
            eventTypes.value = types.data
            users.value = people.data
        } catch (error) {
            console.error('Kon de keuzelijsten voor een afspraak niet ophalen:', error)
            eventTypes.value = []
            users.value = []
        }
    }
})

/** Locaties horen bij één klant, dus die lijst volgt de keuze erboven. */
watch(() => form.customer_id, async (customer_id) => {
    if (props.action?.id === 'nieuwe-storing') {
        selectedAsset.value = null
        form.asset_id = null
        await loadAssets()

        return
    }

    form.location_id = null
    locations.value = []
    if (!customer_id) return
    const { data } = await axios.get('/combo/customers/' + customer_id + '/locations')
    locations.value = data
})

const VISITABLE = {
    'nieuwe-werkbon': { key: 'serviceorder', path: '/serviceorders/' },
    'nieuwe-storing': { key: 'ticket', path: '/tickets/' },
    'nieuwe-klant': { key: 'customer', path: '/customers/' },
}

const canVisit = computed(() => Boolean(VISITABLE[props.action?.id]))

const close = () => { open.value = false }

/**
 * Het net gemaakte record komt als flash mee terug, dus 'direct bezoeken' hoeft
 * er niet naar te zoeken. Komt het onverhoopt niet mee, dan blijft het bij
 * opslaan: ergens heen sturen waar niets staat is erger dan niet gaan.
 */
const done = (visit = false) => {
    saving.value = false
    form.reset()
    open.value = false

    if (!visit) return

    const target = VISITABLE[props.action?.id]
    const created = page.props.flash?.extra?.[target?.key]

    if (target && created?.id) router.visit(target.path + created.id)
}

const submit = (visit = false) => {
    saving.value = true
    const finish = {
        onSuccess: () => done(visit),
        onError: () => { saving.value = false },
        preserveScroll: true,
    }

    if (props.action?.id === 'nieuwe-werkbon') {
        return form.transform((data) => ({ customer_id: data.customer_id, location_id: data.location_id }))
            .post('/serviceorders', finish)
    }

    if (props.action?.id === 'nieuwe-storing') {
        return form.transform((data) => ({
            asset_id: data.asset_id, subject: data.subject, description: data.description,
            priority: data.priority, status: data.status,
        })).post('/tickets', finish)
    }

    if (props.action?.id === 'nieuwe-klant') {
        return form.transform((data) => ({
            name: data.name, email: data.email, phone: data.phone,
            address: data.address, postal_code: data.postal_code, city: data.city,
        })).post('/customers', finish)
    }

    return createAppointment()
}

/**
 * De afspraak gaat naar de JSON-kant van de planner, dus die fouten komen niet
 * vanzelf in form.errors terecht en worden hier overgezet.
 */
const createAppointment = async () => {
    try {
        await axios.get('/sanctum/csrf-cookie')
        await axios.post('/api/events', {
            event_type_id: form.event_type_id,
            status: 'Gepland',
            start: form.date + ' ' + form.start_time,
            end: form.date + ' ' + form.end_time,
            executing_user_ids: form.executing_user_ids,
            no_service_order: true,
        })
        done()
        router.reload()
    } catch (error) {
        saving.value = false
        const errors = error.response?.data?.errors ?? {}
        form.clearErrors()
        form.setError(Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])))
    }
}
</script>
