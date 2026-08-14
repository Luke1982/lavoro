<template>
    <DrawerComponent v-model="open" :title="drawerTitle" subtitle="Vul de gegevens in van het nieuwe contract.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div v-if="templateOptions.length"
                class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Sjabloon</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="templateOptions" v-model="templateId" placeholder="Geen sjabloon" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                        Vult de velden hieronder alvast in. Daarna is alles nog aan te passen.
                    </p>
                </div>
            </div>
            <div v-if="!customer" class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Klant</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="customerOptions" v-model="form.customer_id" placeholder="Selecteer klant"
                        :has-external-searching="customersUseAjax" :searching="customersSearching"
                        @change="searchCustomers"
                        :hasError="Boolean(form.errors.customer_id)" :errorMessage="form.errors.customer_id" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Titel</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.title" type="text" placeholder="Optioneel"
                        :hasError="Boolean(form.errors.title)" :errorMessage="form.errors.title"
                        @update:model-value="titleEditedByHand = true" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Startdatum</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.start_date" type="date"
                        :hasError="Boolean(form.errors.start_date)" :errorMessage="form.errors.start_date" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Einddatum</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.end_date" type="date" placeholder="Optioneel"
                        :hasError="Boolean(form.errors.end_date)" :errorMessage="form.errors.end_date"
                        @update:model-value="endDateEditedByHand = true" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Prijs</label>
                <div class="sm:col-span-2">
                    <CurrencyInput v-model="form.price"
                        :hasError="Boolean(form.errors.price)" :errorMessage="form.errors.price" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Prijsinterval</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="intervalOptions" v-model="form.price_interval"
                        :hasError="Boolean(form.errors.price_interval)" :errorMessage="form.errors.price_interval" />
                </div>
            </div>
            <div v-if="form.price_interval === CUSTOM_CONTRACT_INTERVAL"
                class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Elke ... dagen</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.price_interval_days" type="number"
                        :hasError="Boolean(form.errors.price_interval_days)"
                        :errorMessage="form.errors.price_interval_days" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Frequentie per machine beheren</label>
                <div class="sm:col-span-2">
                    <SwitchComponent v-model="form.manage_frequency_per_asset" />
                </div>
            </div>
            <template v-if="!form.manage_frequency_per_asset">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Servicefrequentie</label>
                    <div class="sm:col-span-2">
                        <ComboBox :options="intervalOptions" v-model="form.frequency"
                            :hasError="Boolean(form.errors.frequency)" :errorMessage="form.errors.frequency" />
                    </div>
                </div>
                <div v-if="form.frequency === CUSTOM_CONTRACT_INTERVAL"
                    class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Elke ... dagen</label>
                    <div class="sm:col-span-2">
                        <TextInput v-model="form.frequency_days" type="number"
                            :hasError="Boolean(form.errors.frequency_days)"
                            :errorMessage="form.errors.frequency_days" />
                    </div>
                </div>
            </template>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">
                    Automatisch werkbonnen genereren
                </label>
                <div class="sm:col-span-2">
                    <SwitchComponent v-model="form.auto_generate" />
                </div>
            </div>
            <template v-if="form.auto_generate">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Genereerinterval</label>
                    <div class="sm:col-span-2">
                        <ComboBox :options="intervalOptions" v-model="form.auto_generate_interval"
                            :placeholder="followsLabel"
                            :hasError="Boolean(form.errors.auto_generate_interval)"
                            :errorMessage="form.errors.auto_generate_interval" />
                    </div>
                </div>
                <div v-if="form.auto_generate_interval === CUSTOM_CONTRACT_INTERVAL"
                    class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                    <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Elke ... dagen</label>
                    <div class="sm:col-span-2">
                        <TextInput v-model="form.auto_generate_interval_days" type="number"
                            :hasError="Boolean(form.errors.auto_generate_interval_days)"
                            :errorMessage="form.errors.auto_generate_interval_days" />
                    </div>
                </div>
            </template>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="open = false"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submit" :disabled="form.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import dayjs from '@/Utilities/dayjs'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import CurrencyInput from '@/Components/UI/CurrencyInput.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import { useComboSearch } from '@/Composables/useComboSearch'
import {
    CUSTOM_CONTRACT_INTERVAL, contractAutoGenerateFollowsLabel, fillContractTitlePlaceholders, todayIso,
} from '@/Utilities/Utilities'

const props = defineProps({
    /** Fixed customer: the picker disappears and the drawer names them in its title. */
    customer: { type: Object, default: null },
    /** Where the picker starts, used only when there is no fixed customer. */
    customers: { type: Array, default: () => [] },
    /** Past the server's threshold the picker searches instead of holding every customer. */
    customersUseAjax: { type: Boolean, default: false },
    templates: { type: Array, default: () => [] },
    contractIntervalOptions: { type: Array, default: () => [] },
})

const open = defineModel({ type: Boolean, default: false })

/**
 * Every field a template fills outright. The dates are kept apart because they are
 * worked out rather than copied — a start date already picked stays standing and
 * the end date follows from the looptijd — and the customer because no template
 * ever touches it.
 */
const templatedDefaults = {
    title: '',
    price: null,
    price_interval: 'Maandelijks',
    price_interval_days: null,
    manage_frequency_per_asset: false,
    frequency: 'Jaarlijks',
    frequency_days: null,
    auto_generate: false,
    auto_generate_interval: null,
    auto_generate_interval_days: null,
}

const form = useForm({
    customer_id: props.customer?.id ?? null,
    start_date: '',
    end_date: '',
    ...templatedDefaults,
})

const templateId = ref(null)
const titleEditedByHand = ref(false)
const endDateEditedByHand = ref(false)

const appliedTemplate = computed(() => props.templates.find(t => t.id === templateId.value) ?? null)

const templateOptions = computed(() => props.templates.map(t => ({ id: t.id, name: t.name })))

const drawerTitle = computed(() =>
    props.customer ? `Nieuw onderhoudscontract voor ${props.customer.name}` : 'Nieuw onderhoudscontract'
)

// comboBoxArray() gives {id: case-name, name: case-value}; the model casts by
// value, so both id and name must be the value for direct v-model binding.
const intervalOptions = computed(() => props.contractIntervalOptions.map(o => ({ id: o.name, name: o.name })))

const followsLabel = computed(() => contractAutoGenerateFollowsLabel(form.manage_frequency_per_asset))

const { options: customerOptions, searching: customersSearching, search: searchCustomers } =
    useComboSearch('customers', props.customers, props.customersUseAjax)

/**
 * Kept from the moment the customer is picked instead of being looked up when the
 * title needs it: searching again replaces the options, and the customer picked
 * before that is no longer among them. The bare name, not the list label, which
 * carries the city behind the name.
 */
const pickedCustomerName = ref('')

const customerName = computed(() => props.customer?.name ?? pickedCustomerName.value)

function rememberCustomerName() {
    const picked = customerOptions.value.find(c => c.id === form.customer_id)

    pickedCustomerName.value = picked ? (picked.plain_name ?? picked.name) : ''
}

/**
 * What a template puts on the contract, its gaps filled by the same defaults an
 * empty form starts with — so picking a template always gives the template, never
 * a leftover of the one picked before it. The title is missing on purpose: it
 * keeps following the customer, and syncTemplatedTitle owns it.
 */
function contractValuesFrom(template) {
    const price = template.price ?? null

    return {
        price: price === null ? null : Number(price),
        price_interval: template.price_interval ?? templatedDefaults.price_interval,
        price_interval_days: template.price_interval_days ?? null,
        manage_frequency_per_asset: Boolean(template.manage_frequency_per_asset),
        frequency: template.frequency ?? templatedDefaults.frequency,
        frequency_days: template.frequency_days ?? null,
        auto_generate: Boolean(template.auto_generate),
        auto_generate_interval: template.auto_generate_interval ?? null,
        auto_generate_interval_days: template.auto_generate_interval_days ?? null,
    }
}

/** The title follows the customer and the start date until it is typed in by hand. */
function syncTemplatedTitle() {
    if (!appliedTemplate.value || titleEditedByHand.value) return

    form.title = fillContractTitlePlaceholders(appliedTemplate.value.title, {
        customerName: customerName.value,
        startDate: form.start_date,
    })
}

/** A looptijd of 12 maanden from 1 januari runs up to and including 31 december. */
function syncTemplatedEndDate() {
    if (!appliedTemplate.value || endDateEditedByHand.value) return

    const months = appliedTemplate.value.duration_months

    form.end_date = months && form.start_date
        ? dayjs(form.start_date).add(months, 'month').subtract(1, 'day').format('YYYY-MM-DD')
        : ''
}

/**
 * Deselecting a template keeps what is on screen: the fields have been filled in,
 * they just stop following a template from here on.
 */
watch(templateId, () => {
    titleEditedByHand.value = false
    endDateEditedByHand.value = false

    const template = appliedTemplate.value
    if (!template) return

    Object.assign(form, contractValuesFrom(template))
    form.clearErrors()
    form.start_date = form.start_date || todayIso()
    syncTemplatedEndDate()
    syncTemplatedTitle()
})

watch([() => form.customer_id, () => form.start_date], ([customer_id], [previous_customer_id]) => {
    if (customer_id !== previous_customer_id) {
        rememberCustomerName()
    }

    syncTemplatedEndDate()
    syncTemplatedTitle()
})

/**
 * Emptied on the way out rather than on the way in, and by watching `open` rather
 * than by the buttons: the drawer also closes on its own backdrop and on Escape,
 * and a half-filled form must not be waiting there the next time.
 */
watch(open, isOpen => {
    if (isOpen) return

    templateId.value = null
    titleEditedByHand.value = false
    endDateEditedByHand.value = false
    form.reset()
    form.clearErrors()
})

function submit() {
    form.post('/maintenancecontracts', {
        preserveScroll: true,
        onSuccess: () => { open.value = false },
    })
}
</script>
