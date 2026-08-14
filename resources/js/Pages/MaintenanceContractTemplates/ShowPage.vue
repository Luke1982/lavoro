<template>
    <div class="flex items-center">
        <Link href="/maintenancecontracttemplates" class="text-slate-400 text-sm font-medium">Contractsjablonen</Link>
        <ChevronRightIcon class="size-4 text-gray-400 mx-2" />
        <span class="text-slate-800 dark:text-slate-100 font-bold text-sm">{{ template.name }}</span>
    </div>

    <div class="flex flex-col mt-6 mb-4">
        <h1 class="text-2xl font-bold dark:text-slate-100">{{ template.name }}</h1>
        <p class="text-gray-500 dark:text-slate-400 text-sm mt-1">
            Wat hier staat wordt ingevuld op een nieuw contract. De klant en de machines kies je op het contract zelf.
        </p>
    </div>

    <BoxComponent>
        <SectionHeader :icon="DocumentDuplicateIcon" title="Sjabloongegevens"
            subtitle="Wat dit sjabloon invult op een nieuw contract." chapter="details" />
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6" v-auto-animate>
            <EditableTextField v-model="form.name" type="input" label="Naam"
                :error="form.errors.name" :readonly="!canUpdate"
                @update="() => patch('name')" @revert="form.clearErrors('name')" />
            <div>
                <EditableTextField v-model="form.title" type="input" label="Contracttitel"
                    placeholder="Geen titel" clearable
                    :error="form.errors.title" :readonly="!canUpdate"
                    @update="() => patch('title')" @revert="form.clearErrors('title')" />
                <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">{{ contractTitlePlaceholderHint }}</p>
            </div>
            <EditableTextField v-model="form.duration_months" type="input" inputType="number" label="Looptijd in maanden"
                placeholder="Doorlopend" clearable
                :error="form.errors.duration_months" :readonly="!canUpdate"
                @update="() => patch('duration_months')" @revert="form.clearErrors('duration_months')" />
            <template v-if="canSeeFinancials">
                <EditableTextField v-model="form.price" type="input" inputType="currency" label="Prijs"
                    placeholder="Geen prijs" clearable
                    :error="form.errors.price" :readonly="!canUpdate"
                    @update="() => patch('price')" @revert="form.clearErrors('price')" />
                <EditableTextField v-model="form.price_interval" type="combobox" label="Prijsinterval"
                    :options="intervalOptions" placeholder="Geen prijsinterval"
                    :error="form.errors.price_interval" :readonly="!canUpdate"
                    @update="() => patch('price_interval')" @revert="form.clearErrors('price_interval')" />
                <EditableTextField v-if="form.price_interval === CUSTOM_CONTRACT_INTERVAL"
                    v-model="form.price_interval_days" type="input" inputType="number" label="Elke ... dagen"
                    :error="form.errors.price_interval_days" :readonly="!canUpdate"
                    @update="() => patch('price_interval_days')" @revert="form.clearErrors('price_interval_days')" />
            </template>
        </div>
    </BoxComponent>

    <BoxComponent class="mt-4">
        <SectionHeader :icon="ClockIcon" title="Servicefrequentie"
            subtitle="Hoe vaak er onderhoud plaatsvindt onder een contract van dit sjabloon." chapter="frequency">
            <template #actions>
                <span class="text-xs text-gray-500 dark:text-slate-400">Per machine beheren</span>
                <SwitchComponent v-model="form.manage_frequency_per_asset" :disabled="!canUpdate"
                    @update:model-value="() => patch('manage_frequency_per_asset')" />
            </template>
        </SectionHeader>
        <div v-if="!form.manage_frequency_per_asset" class="grid grid-cols-1 md:grid-cols-2 gap-6" v-auto-animate>
            <EditableTextField v-model="form.frequency" type="combobox" label="Servicefrequentie"
                :options="intervalOptions" placeholder="Geen servicefrequentie"
                :error="form.errors.frequency" :readonly="!canUpdate"
                @update="() => patch('frequency')" @revert="form.clearErrors('frequency')" />
            <EditableTextField v-if="form.frequency === CUSTOM_CONTRACT_INTERVAL"
                v-model="form.frequency_days" type="input" inputType="number" label="Elke ... dagen"
                :error="form.errors.frequency_days" :readonly="!canUpdate"
                @update="() => patch('frequency_days')" @revert="form.clearErrors('frequency_days')" />
        </div>
        <p v-else class="text-sm text-gray-500 dark:text-slate-400">
            Contracten van dit sjabloon krijgen hun frequentie per machine.
        </p>
    </BoxComponent>

    <BoxComponent class="mt-4">
        <SectionHeader :icon="ClipboardDocumentListIcon" title="Werkbonnen" :subtitle="autoGenerateSummary"
            chapter="serviceorders">
            <template #actions>
                <span class="text-xs text-gray-500 dark:text-slate-400">Automatisch genereren</span>
                <SwitchComponent v-model="form.auto_generate" :disabled="!canUpdate"
                    @update:model-value="() => patch('auto_generate')" />
            </template>
        </SectionHeader>
        <div v-if="form.auto_generate" class="grid grid-cols-1 md:grid-cols-2 gap-6" v-auto-animate>
            <EditableTextField v-model="form.auto_generate_interval" type="combobox" label="Genereerinterval"
                :options="intervalOptions" :placeholder="followsLabel"
                :error="form.errors.auto_generate_interval" :readonly="!canUpdate"
                @update="() => patch('auto_generate_interval')"
                @revert="form.clearErrors('auto_generate_interval')" />
            <EditableTextField v-if="form.auto_generate_interval === CUSTOM_CONTRACT_INTERVAL"
                v-model="form.auto_generate_interval_days" type="input" inputType="number" label="Elke ... dagen"
                :error="form.errors.auto_generate_interval_days" :readonly="!canUpdate"
                @update="() => patch('auto_generate_interval_days')"
                @revert="form.clearErrors('auto_generate_interval_days')" />
        </div>
    </BoxComponent>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import {
    ChevronRightIcon, ClipboardDocumentListIcon, ClockIcon, DocumentDuplicateIcon,
} from '@heroicons/vue/24/outline'
import BoxComponent from '@/Components/BoxComponent.vue'
import SectionHeader from '@/Components/UI/SectionHeader.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import {
    CUSTOM_CONTRACT_INTERVAL, contractAutoGenerateFollowsLabel, contractIntervalLabel,
    contractTitlePlaceholderHint, hasPermission,
} from '@/Utilities/Utilities'

const props = defineProps({
    template: { type: Object, required: true },
    contractIntervalOptions: { type: Array, default: () => [] },
})

const canUpdate = computed(() => hasPermission('maintenancecontracttemplate.update'))
const canSeeFinancials = computed(() => hasPermission('maintenancecontract.see_financials'))

// comboBoxArray() gives {id: case-name, name: case-value}; the model casts by
// value, so both id and name must be the value for direct v-model binding.
const intervalOptions = computed(() => props.contractIntervalOptions.map(o => ({ id: o.name, name: o.name })))

const form = useForm({
    name: props.template.name,
    title: props.template.title ?? '',
    duration_months: props.template.duration_months,
    price: props.template.price,
    price_interval: props.template.price_interval,
    price_interval_days: props.template.price_interval_days,
    manage_frequency_per_asset: props.template.manage_frequency_per_asset,
    frequency: props.template.frequency,
    frequency_days: props.template.frequency_days,
    auto_generate: props.template.auto_generate,
    auto_generate_interval: props.template.auto_generate_interval,
    auto_generate_interval_days: props.template.auto_generate_interval_days,
})

const followsLabel = computed(() => contractAutoGenerateFollowsLabel(form.manage_frequency_per_asset))

const autoGenerateSummary = computed(() => {
    if (!form.auto_generate) return 'Contracten van dit sjabloon krijgen hun werkbonnen met de hand'

    const interval = contractIntervalLabel(form.auto_generate_interval, form.auto_generate_interval_days)

    return interval
        ? `Automatisch: ${interval}`
        : `Automatisch: ${followsLabel.value.toLowerCase()}`
})

function patch(...fields) {
    form.transform(data => Object.fromEntries(fields.map(field => [field, data[field]])))
        .patch(`/maintenancecontracttemplates/${props.template.id}`, { preserveScroll: true })
}
</script>
