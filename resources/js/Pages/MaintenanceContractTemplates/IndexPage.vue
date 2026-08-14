<template>
    <IndexHeaderComponent title="Contractsjablonen"
        subtitle="Vaste afspraken die je op een nieuw onderhoudscontract toepast" :show-search="false"
        add-label="Voeg sjabloon toe" @add="showCreateDrawer = true"
        :can-add="hasPermission('maintenancecontracttemplate.create')" />

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="templates.length">
            <div
                class="hidden md:grid md:grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-3">Naam</div>
                <div class="col-span-2">Looptijd</div>
                <div class="col-span-2">Prijs</div>
                <div class="col-span-2">Servicefrequentie</div>
                <div class="col-span-2">Werkbonnen</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="template in templates" :key="template.id"
                class="relative md:grid md:grid-cols-12 p-4 pr-14 md:pr-4 text-sm border-b-lavoro-gray-150 border-b-2 md:items-center">
                <div class="flex flex-col py-1 md:py-0 md:col-span-3">
                    <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Naam</span>
                    <Link :href="`/maintenancecontracttemplates/${template.id}`"
                        class="font-medium text-gray-900 dark:text-slate-100 hover:underline">
                        {{ template.name }}
                    </Link>
                    <span v-if="template.title" class="text-gray-500 dark:text-slate-400">{{ template.title }}</span>
                </div>
                <div class="flex flex-col py-1 md:py-0 md:col-span-2">
                    <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Looptijd</span>
                    <span class="text-gray-500 dark:text-slate-400">{{ durationLabel(template) }}</span>
                </div>
                <div class="flex flex-col py-1 md:py-0 md:col-span-2">
                    <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Prijs</span>
                    <span v-if="canSeeFinancials" class="text-gray-500 dark:text-slate-400">{{ priceLabel(template) }}</span>
                </div>
                <div class="flex flex-col py-1 md:py-0 md:col-span-2">
                    <span
                        class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Servicefrequentie</span>
                    <span class="text-gray-500 dark:text-slate-400">{{ frequencyLabel(template) }}</span>
                </div>
                <div class="flex flex-col py-1 md:py-0 md:col-span-2">
                    <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Werkbonnen</span>
                    <span class="text-gray-500 dark:text-slate-400">{{ autoGenerateLabel(template) }}</span>
                </div>
                <div class="absolute right-4 top-4 md:static md:col-span-1 md:flex md:justify-end">
                    <div v-if="hasPermission('maintenancecontracttemplate.delete')"
                        class="border-1 border-lavoro-darkergray rounded-full p-2">
                        <TrashIcon class="h-5 w-5 cursor-pointer text-red-500" @click="deleteTemplate(template)" />
                    </div>
                </div>
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <DocumentDuplicateIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Nog geen contractsjablonen</p>
            </div>
        </div>
    </BoxComponent>

    <DrawerComponent v-model="showCreateDrawer" title="Nieuw contractsjabloon"
        subtitle="Alles behalve de klant en de machines ligt hiermee vast.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Naam</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.name" type="text" placeholder="Bijv. Standaard jaarcontract"
                        :hasError="Boolean(form.errors.name)" :errorMessage="form.errors.name" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Contracttitel</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.title" type="text" placeholder="Optioneel"
                        :hasError="Boolean(form.errors.title)" :errorMessage="form.errors.title" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">{{ contractTitlePlaceholderHint }}</p>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Looptijd in maanden</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="form.duration_months" type="number" placeholder="Leeg = doorlopend"
                        :hasError="Boolean(form.errors.duration_months)"
                        :errorMessage="form.errors.duration_months" />
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
                    <ComboBox :options="intervalOptions" v-model="form.price_interval" placeholder="Geen prijsinterval"
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
                            placeholder="Geen servicefrequentie"
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
                <button type="button" @click="showCreateDrawer = false"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitCreate" :disabled="form.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Opslaan
                </button>
            </div>
        </template>
    </DrawerComponent>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { DocumentDuplicateIcon, TrashIcon } from '@heroicons/vue/24/outline'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import DrawerComponent from '@/Components/UI/DrawerComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import CurrencyInput from '@/Components/UI/CurrencyInput.vue'
import SwitchComponent from '@/Components/UI/SwitchComponent.vue'
import {
    CUSTOM_CONTRACT_INTERVAL, contractAutoGenerateFollowsLabel, contractIntervalLabel,
    contractTitlePlaceholderHint, hasPermission, nlCurrency,
} from '@/Utilities/Utilities'

const props = defineProps({
    templates: { type: Array, default: () => [] },
    contractIntervalOptions: { type: Array, default: () => [] },
})

const canSeeFinancials = computed(() => hasPermission('maintenancecontract.see_financials'))

// comboBoxArray() gives {id: case-name, name: case-value}; the model casts by
// value, so both id and name must be the value for direct v-model binding.
const intervalOptions = computed(() => props.contractIntervalOptions.map(o => ({ id: o.name, name: o.name })))

const durationLabel = template => {
    if (!template.duration_months) return 'Doorlopend'

    return template.duration_months === 1 ? '1 maand' : `${template.duration_months} maanden`
}

const priceLabel = template => {
    const price = template.price ?? null

    return price === null ? '—' : `${nlCurrency(price)} / ${template.price_interval ?? 'onbepaald'}`
}

const frequencyLabel = template => {
    if (template.manage_frequency_per_asset) return 'Per machine'

    return contractIntervalLabel(template.frequency, template.frequency_days) || '—'
}

const autoGenerateLabel = template => {
    if (!template.auto_generate) return 'Handmatig'

    const interval = contractIntervalLabel(template.auto_generate_interval, template.auto_generate_interval_days)

    return interval ? `Automatisch (${interval.toLowerCase()})` : 'Automatisch'
}

const showCreateDrawer = ref(false)

const form = useForm({
    name: '',
    title: '',
    duration_months: null,
    price: null,
    price_interval: 'Maandelijks',
    price_interval_days: null,
    manage_frequency_per_asset: false,
    frequency: 'Jaarlijks',
    frequency_days: null,
    auto_generate: false,
    auto_generate_interval: null,
    auto_generate_interval_days: null,
})

const followsLabel = computed(() => contractAutoGenerateFollowsLabel(form.manage_frequency_per_asset))

function submitCreate() {
    form.post('/maintenancecontracttemplates', {
        preserveScroll: true,
        onSuccess: () => { showCreateDrawer.value = false },
    })
}

watch(showCreateDrawer, isOpen => {
    if (isOpen) return

    form.reset()
    form.clearErrors()
})

function deleteTemplate(template) {
    if (!confirm(`Weet je zeker dat je sjabloon "${template.name}" wilt verwijderen?`)) return

    useForm({}).delete(`/maintenancecontracttemplates/${template.id}`, {
        preserveScroll: true,
        preserveState: true,
    })
}
</script>
