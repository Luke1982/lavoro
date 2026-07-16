<template>
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
        <div class="flex items-center">
            <Link href="/maintenancecontracts" class="text-slate-400 text-sm font-medium">Onderhoudscontracten</Link>
            <ChevronRightIcon class="size-4 text-gray-400 mx-2" />
            <span class="text-slate-800 dark:text-slate-100 font-bold text-sm">{{ maintenanceContract.display_title }}</span>
        </div>
        <div v-if="canUpdate" class="w-full sm:w-auto">
            <SelectMenuComponent v-model="statusValue" :options="statusOptions" label="Status"
                class="w-full sm:w-auto" @update:model-value="updateStatus" />
        </div>
    </div>

    <div class="flex flex-col mt-6 mb-4">
        <div class="flex items-center gap-2 flex-wrap">
            <h1 class="text-2xl font-bold dark:text-slate-100">{{ maintenanceContract.display_title }}</h1>
            <BadgeComponent :color="maintenanceContractStatusBadgeColor(maintenanceContract.status)" :has-dot="false">
                {{ maintenanceContractStatusText(maintenanceContract.status) }}
            </BadgeComponent>
        </div>
        <Link :href="`/customers/${selectedCustomer.id}`"
            class="text-gray-500 dark:text-slate-400 text-sm mt-1 hover:underline">
            {{ selectedCustomer.name }}
        </Link>
    </div>

    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <div class="flex items-center mb-4">
                    <ClipboardDocumentCheckIcon class="size-6 mr-2 flex-none text-gray-500 dark:text-slate-400" />
                    <span class="text-md font-bold dark:text-slate-100">Contractgegevens</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6" v-auto-animate>
                    <EditableTextField v-model="form.customer_id" type="combobox" label="Klant"
                        :options="internalCustomers"
                        :error="form.errors.customer_id" :readonly="!canUpdate"
                        @update="onCustomerUpdate" @revert="form.clearErrors('customer_id')">
                        <template #display>
                            <component :is="hasPermission('customer.read') ? Link : 'span'"
                                :href="`/customers/${selectedCustomer.id}`"
                                :class="{ 'underline text-lavoro-blue': hasPermission('customer.read') }">
                                {{ selectedCustomer.name }}
                            </component>
                        </template>
                    </EditableTextField>
                    <EditableTextField v-model="form.title" type="input" label="Titel" placeholder="Geen titel"
                        :error="form.errors.title" :readonly="!canUpdate"
                        @update="() => patch('title')" @revert="form.clearErrors('title')" />
                    <EditableTextField v-model="form.start_date" type="input" inputType="date" label="Startdatum"
                        :error="form.errors.start_date" :readonly="!canUpdate"
                        @update="() => patch('start_date')" @revert="form.clearErrors('start_date')" />
                    <EditableTextField v-model="form.end_date" type="input" inputType="date" label="Einddatum"
                        placeholder="Geen einddatum"
                        :error="form.errors.end_date" :readonly="!canUpdate"
                        @update="() => patch('end_date')" @revert="form.clearErrors('end_date')" />
                    <EditableTextField v-model="form.price" type="input" inputType="currency" label="Prijs"
                        :error="form.errors.price" :readonly="!canUpdate"
                        @update="() => patch('price')" @revert="form.clearErrors('price')" />
                    <EditableTextField v-model="form.price_interval" type="combobox" label="Prijsinterval"
                        :options="intervalOptions"
                        :error="form.errors.price_interval" :readonly="!canUpdate"
                        @update="() => patch('price_interval', 'price_interval_days')"
                        @revert="form.clearErrors('price_interval')" />
                    <EditableTextField v-if="form.price_interval === 'Aangepast (dagen)'"
                        v-model="form.price_interval_days" type="input" inputType="number" label="Elke ... dagen"
                        :error="form.errors.price_interval_days" :readonly="!canUpdate"
                        @update="() => patch('price_interval_days')" @revert="form.clearErrors('price_interval_days')" />
                </div>
            </BoxComponent>

            <BoxComponent class="mt-4">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center">
                        <ClockIcon class="size-6 mr-2 flex-none text-gray-500 dark:text-slate-400" />
                        <span class="text-md font-bold dark:text-slate-100">Servicefrequentie</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500 dark:text-slate-400">Per machine beheren</span>
                        <SwitchComponent v-model="form.manage_frequency_per_asset" :disabled="!canUpdate"
                            @update:model-value="() => patch('manage_frequency_per_asset', 'frequency', 'frequency_days')" />
                    </div>
                </div>
                <div v-if="!form.manage_frequency_per_asset" class="grid grid-cols-1 md:grid-cols-2 gap-6" v-auto-animate>
                    <EditableTextField v-model="form.frequency" type="combobox" label="Servicefrequentie"
                        :options="intervalOptions"
                        :error="form.errors.frequency" :readonly="!canUpdate"
                        @update="() => patch('frequency', 'frequency_days')" @revert="form.clearErrors('frequency')" />
                    <EditableTextField v-if="form.frequency === 'Aangepast (dagen)'"
                        v-model="form.frequency_days" type="input" inputType="number" label="Elke ... dagen"
                        :error="form.errors.frequency_days" :readonly="!canUpdate"
                        @update="() => patch('frequency_days')" @revert="form.clearErrors('frequency_days')" />
                </div>
                <p v-else class="text-sm text-gray-500 dark:text-slate-400">
                    Frequentie wordt per machine ingesteld hieronder.
                </p>
            </BoxComponent>

            <BoxComponent class="mt-4">
                <MaintenanceContractAssetsWidget :maintenance-contract-id="maintenanceContract.id"
                    :assets="maintenanceContract.assets" :customer-assets="customerAssets"
                    :manage-per-asset="maintenanceContract.manage_frequency_per_asset"
                    :interval-options="intervalOptions" />
                <div v-if="contractLocations.length" class="mt-4 flex flex-wrap items-center gap-1">
                    <span class="text-xs text-gray-500 dark:text-slate-400 mr-1">Locaties in dit contract:</span>
                    <BadgeComponent v-for="loc in contractLocations" :key="loc.id" color="gray" :has-dot="false"
                        :url="`/locations/${loc.id}`">{{ loc.title }}</BadgeComponent>
                </div>
            </BoxComponent>

            <BoxComponent class="mt-4">
                <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
                    <div class="flex items-center">
                        <DocumentTextIcon class="size-6 mr-2 flex-none text-gray-500 dark:text-slate-400" />
                        <span class="text-md font-bold dark:text-slate-100">Werkbonnen</span>
                    </div>
                    <div class="flex items-center gap-4">
                        <div v-if="canUpdate" class="flex items-center gap-2">
                            <span class="text-xs text-gray-500 dark:text-slate-400">Automatisch genereren</span>
                            <SwitchComponent v-model="autoGenerateSwitch" @update="handleAutoGenerateToggle" />
                        </div>
                        <button v-if="canGenerate" type="button"
                            class="text-sm font-medium text-lavoro-blue hover:underline disabled:opacity-50"
                            :disabled="generating" @click="generateServiceOrders">
                            Werkbon aanmaken
                        </button>
                    </div>
                </div>
                <p class="text-xs text-gray-500 dark:text-slate-400 mb-3">
                    {{ autoGenerateSummary }}
                </p>
                <ul v-if="maintenanceContract.generated_service_orders?.length"
                    class="divide-y divide-gray-100 dark:divide-slate-700 mt-3">
                    <li v-for="so in maintenanceContract.generated_service_orders" :key="so.id"
                        class="py-2 flex items-center justify-between gap-2">
                        <div class="flex flex-col">
                            <Link :href="`/serviceorders/${so.id}`" class="font-medium hover:underline">
                                Werkbon #{{ so.id }}
                            </Link>
                            <span class="text-xs text-gray-500 dark:text-slate-400">
                                {{ generatedServiceOrderAssetLabel(so) }} — {{ nlDate(so.created_at) }}
                            </span>
                        </div>
                        <BadgeComponent :color="so.service_order_stage ? 'blue' : 'gray'" :has-dot="false">
                            {{ so.service_order_stage?.name ?? 'Geen fase' }}
                        </BadgeComponent>
                    </li>
                </ul>
                <p v-else class="text-sm text-gray-500 dark:text-slate-400">Nog geen werkbonnen gegenereerd.</p>
            </BoxComponent>

            <ModalDialog v-model:open="showAutoGenerateModal" title="Automatisch werkbonnen genereren"
                max-width-class="sm:max-w-md" @update:open="(v) => { if (!v) cancelAutoGenerateModal() }">
                <div v-if="modalStep === 'choice'">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Moeten werkbonnen automatisch aangemaakt worden volgens {{ contractFrequencyLabel }},
                        of wil je hiervoor een andere frequentie instellen?
                    </p>
                </div>
                <div v-else class="space-y-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Kies de frequentie voor automatische generatie.
                    </p>
                    <ComboBox :options="intervalOptions" v-model="modalInterval" placeholder="Selecteer frequentie" />
                    <TextInput v-if="modalInterval === 'Aangepast (dagen)'" v-model="modalIntervalDays" type="number"
                        label="Elke ... dagen" />
                </div>
                <template #footer>
                    <div v-if="modalStep === 'choice'" class="flex flex-col sm:flex-row gap-2 sm:justify-end">
                        <button type="button"
                            class="rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-700"
                            @click="cancelAutoGenerateModal">
                            Annuleren
                        </button>
                        <button type="button"
                            class="rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-700"
                            @click="modalStep = 'custom'">
                            Andere frequentie instellen
                        </button>
                        <button type="button"
                            class="inline-flex justify-center rounded-md bg-lavoro-blue px-3 py-2 text-sm font-semibold text-white shadow-xs hover:opacity-90"
                            @click="confirmUseContractFrequency">
                            Gebruik {{ contractFrequencyLabel }}
                        </button>
                    </div>
                    <div v-else class="flex justify-end gap-2">
                        <button type="button"
                            class="rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-700"
                            @click="modalStep = 'choice'">
                            Terug
                        </button>
                        <button type="button"
                            class="inline-flex justify-center rounded-md bg-lavoro-blue px-3 py-2 text-sm font-semibold text-white shadow-xs hover:opacity-90"
                            @click="confirmCustomInterval">
                            Bevestigen
                        </button>
                    </div>
                </template>
            </ModalDialog>
        </template>

        <template #sidebar>
            <BoxComponent>
                <div class="flex items-center mb-4">
                    <ClockIcon class="size-6 mr-2 flex-none text-gray-500 dark:text-slate-400" />
                    <span class="text-md font-bold dark:text-slate-100">Activiteiten</span>
                </div>
                <TimelineComponent :activities="maintenanceContract.activities || []" />
            </BoxComponent>

            <BoxComponent class="mt-4">
                <RemarksComponent :remarkable-type="'App\\Models\\MaintenanceContract'"
                    :remarkable-id="maintenanceContract.id" :comments="maintenanceContract.remarks || []" />
            </BoxComponent>
        </template>
    </TwoThirdsOneThird>

    <CustomerTransferModal v-model:open="showTransferModal" context="contract"
        :subject-id="maintenanceContract.id" :customer-id="form.customer_id"
        :new-customer-name="selectedCustomer?.name ?? ''"
        @confirm="onTransferConfirm" @cancel="onTransferCancel" />
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import { ChevronRightIcon, ClipboardDocumentCheckIcon, ClockIcon, DocumentTextIcon } from '@heroicons/vue/24/outline'
import BoxComponent from '@/Components/BoxComponent.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import SelectMenuComponent from '@/Components/UI/SelectMenuComponent.vue'
import BadgeComponent from '@/Components/UI/BadgeComponent.vue'
import ModalDialog from '@/Components/UI/ModalDialog.vue'
import CustomerTransferModal from '@/Components/UI/CustomerTransferModal.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import TimelineComponent from '@/Components/Timeline/TimelineComponent.vue'
import RemarksComponent from '@/Components/RemarksComponent.vue'
import MaintenanceContractAssetsWidget from '@/Components/MaintenanceContracts/MaintenanceContractAssetsWidget.vue'
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue'
import { hasPermission, nlDate, maintenanceContractStatusText, maintenanceContractStatusBadgeColor } from '@/Utilities/Utilities'

const props = defineProps({
    maintenanceContract: { type: Object, required: true },
    customerAssets: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    contractIntervalOptions: { type: Array, default: () => [] },
})

const canUpdate = computed(() => hasPermission('maintenancecontract.update'))
const canGenerate = computed(() => hasPermission('maintenancecontract.generate'))

const contractLocations = computed(() => {
    const map = new Map()
    ;(props.maintenanceContract.assets || []).forEach(a => {
        if (a.linked_location) map.set(a.linked_location.id, a.linked_location)
    })
    return [...map.values()]
})
const generating = ref(false)

function generateServiceOrders() {
    generating.value = true
    router.post(`/maintenancecontracts/${props.maintenanceContract.id}/generate-serviceorders`, {}, {
        preserveScroll: true,
        onFinish: () => { generating.value = false },
    })
}

const autoGenerateSwitch = ref(props.maintenanceContract.auto_generate)
const showAutoGenerateModal = ref(false)
const modalStep = ref('choice')
const modalInterval = ref(props.maintenanceContract.auto_generate_interval || props.maintenanceContract.frequency || 'Maandelijks')
const modalIntervalDays = ref(props.maintenanceContract.auto_generate_interval_days || props.maintenanceContract.frequency_days || 1)

function frequencyDisplay(interval, days) {
    if (interval === 'Aangepast (dagen)' && days) return `elke ${days} dagen`
    return interval
}

const contractFrequencyLabel = computed(() => {
    if (props.maintenanceContract.manage_frequency_per_asset) return 'de frequentie per machine'
    return frequencyDisplay(props.maintenanceContract.frequency, props.maintenanceContract.frequency_days)
})

const autoGenerateSummary = computed(() => {
    if (!props.maintenanceContract.auto_generate) {
        return `Automatisch genereren staat uit (zou ${contractFrequencyLabel.value} gebruiken)`
    }
    if (props.maintenanceContract.auto_generate_interval) {
        return 'Automatisch: ' + frequencyDisplay(
            props.maintenanceContract.auto_generate_interval,
            props.maintenanceContract.auto_generate_interval_days
        )
    }
    return `Automatisch: volgt ${contractFrequencyLabel.value}`
})

function handleAutoGenerateToggle(newVal) {
    if (newVal) {
        modalStep.value = 'choice'
        showAutoGenerateModal.value = true
    } else {
        patchAutoGenerate({ auto_generate: false, auto_generate_interval: null, auto_generate_interval_days: null })
    }
}

function confirmUseContractFrequency() {
    showAutoGenerateModal.value = false
    patchAutoGenerate({ auto_generate: true, auto_generate_interval: null, auto_generate_interval_days: null })
}

function confirmCustomInterval() {
    showAutoGenerateModal.value = false
    patchAutoGenerate({
        auto_generate: true,
        auto_generate_interval: modalInterval.value,
        auto_generate_interval_days: modalInterval.value === 'Aangepast (dagen)' ? modalIntervalDays.value : null,
    })
}

function cancelAutoGenerateModal() {
    showAutoGenerateModal.value = false
    autoGenerateSwitch.value = false
}

function patchAutoGenerate(payload) {
    useForm(payload).patch(`/maintenancecontracts/${props.maintenanceContract.id}`, { preserveScroll: true })
}

function generatedServiceOrderAssetLabel(serviceOrder) {
    const jobs = serviceOrder.service_jobs || []
    if (!jobs.length) return 'Onbekende machine'
    return jobs.map(job => {
        const asset = job.asset
        if (!asset) return 'Onbekende machine'
        const name = [asset.product?.brand?.name, asset.product?.model].filter(Boolean).join(' ')
        return asset.serial_number ? (name ? `${name} (${asset.serial_number})` : asset.serial_number) : (name || `#${asset.id}`)
    }).join(', ')
}

const statusOptions = [
    { value: 'actief', title: 'Actief' },
    { value: 'geannuleerd', title: 'Geannuleerd' },
]
const statusValue = ref(props.maintenanceContract.cancelled_at ? 'geannuleerd' : 'actief')

function updateStatus(value) {
    useForm({ cancelled: value === 'geannuleerd' }).patch(
        `/maintenancecontracts/${props.maintenanceContract.id}`,
        { preserveScroll: true }
    )
}

// comboBoxArray() gives {id: case-name, name: case-value}; the model casts by
// value, so both id and name must be the value for direct v-model binding.
const intervalOptions = computed(() => props.contractIntervalOptions.map(o => ({ id: o.name, name: o.name })))

const internalCustomers = computed(() => props.customers.map(c => ({ id: c.id, name: c.name })))
const selectedCustomer = computed(() =>
    props.customers.find(c => c.id === form.customer_id) ?? props.maintenanceContract.customer
)

const form = useForm({
    customer_id: props.maintenanceContract.customer.id,
    title: props.maintenanceContract.title ?? '',
    start_date: props.maintenanceContract.start_date,
    end_date: props.maintenanceContract.end_date,
    price: props.maintenanceContract.price,
    price_interval: props.maintenanceContract.price_interval,
    price_interval_days: props.maintenanceContract.price_interval_days,
    manage_frequency_per_asset: props.maintenanceContract.manage_frequency_per_asset,
    frequency: props.maintenanceContract.frequency,
    frequency_days: props.maintenanceContract.frequency_days,
})

function patch(...fields) {
    form.transform(data => {
        const payload = {}
        fields.forEach(f => { payload[f] = data[f] })
        return payload
    }).patch(`/maintenancecontracts/${props.maintenanceContract.id}`, { preserveScroll: true })
}

const showTransferModal = ref(false)

/**
 * The machines on this contract belong to the current customer, so handing the contract to
 * someone else has to say what happens to them. Confirm first, then save customer and
 * strategy together — a bare customer_id would be rejected by the backend anyway.
 */
function onCustomerUpdate() {
    if (form.customer_id === props.maintenanceContract.customer.id) {
        return
    }

    if (!props.maintenanceContract.assets.length) {
        patch('customer_id')
        return
    }

    showTransferModal.value = true
}

function onTransferConfirm({ asset_strategy, location_map }) {
    form.transform(data => ({
        customer_id: data.customer_id,
        asset_strategy,
        location_map,
    })).patch(`/maintenancecontracts/${props.maintenanceContract.id}`, {
        preserveScroll: true,
        onFinish: () => form.transform(data => data),
    })
}

function onTransferCancel() {
    form.customer_id = props.maintenanceContract.customer.id
}
</script>
