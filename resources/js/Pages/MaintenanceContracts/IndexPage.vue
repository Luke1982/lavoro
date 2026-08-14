<template>
    <IndexHeaderComponent title="Onderhoudscontracten" subtitle="Overzicht van alle onderhoudscontracten"
        search-url="/maintenancecontracts" search-placeholder="Zoek..." :search-other-params="filterParams"
        add-label="Voeg contract toe" @add="showCreateDrawer = true"
        :can-add="hasPermission('maintenancecontract.create')"
        :has-active-filters="Boolean(customerFilter) || Boolean(statusFilter)">
        <template #filters>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Klant</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="customerOptions" v-model="customerFilter" placeholder="Alle klanten"
                        :has-external-searching="customersUseAjax" :searching="customersSearching"
                        @change="searchCustomers" />
                </div>
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Status</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="statusFilterOptions" v-model="statusFilter" placeholder="Alle statussen" />
                </div>
            </div>
        </template>
    </IndexHeaderComponent>

    <PaginationComponent v-if="(maintenanceContracts.links || []).length" :paginator="maintenanceContracts"
        :params="{ search: searchParam, ...filterParams }" class="border-b border-gray-200 dark:border-slate-700/60" />

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div v-if="maintenanceContracts.data?.length">
            <div
                class="hidden md:grid md:grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
                <div class="col-span-3">Klant</div>
                <div class="col-span-2">Contract</div>
                <div class="col-span-2">Periode</div>
                <div class="col-span-2">Prijs</div>
                <div class="col-span-2">Status</div>
                <div class="col-span-1 text-right">Acties</div>
            </div>
            <div v-for="contract in maintenanceContracts.data" :key="contract.id"
                class="relative md:grid md:grid-cols-12 p-4 pr-14 md:pr-4 text-sm border-b-lavoro-gray-150 border-b-2 md:items-center">
                <div class="flex flex-col py-1 md:py-0 md:col-span-3">
                    <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Klant</span>
                    <Link :href="`/maintenancecontracts/${contract.id}`" class="text-gray-900 dark:text-slate-100 hover:underline">
                        {{ contract.customer?.name }}
                    </Link>
                </div>
                <div class="flex flex-col py-1 md:py-0 md:col-span-2">
                    <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Contract</span>
                    <Link :href="`/maintenancecontracts/${contract.id}`" class="font-medium text-gray-900 dark:text-slate-100 hover:underline">
                        {{ contract.display_title }}
                    </Link>
                </div>
                <div class="flex flex-col py-1 md:py-0 md:col-span-2">
                    <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Periode</span>
                    <span class="text-gray-500 dark:text-slate-400">
                        {{ nlDate(contract.start_date) }} – {{ contract.end_date ? nlDate(contract.end_date) : 'heden' }}
                    </span>
                </div>
                <div class="flex flex-col py-1 md:py-0 md:col-span-2">
                    <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Prijs</span>
                    <span class="text-gray-500 dark:text-slate-400">
                        <div v-if="hasPermission('maintenancecontract.see_financials')">
{{ nlCurrency(contract.price) }} / {{ contract.price_interval }}
                        </div>
                    </span>
                </div>
                <div class="flex flex-col py-1 md:py-0 md:col-span-2">
                    <span class="block md:hidden font-semibold text-xs text-gray-500 dark:text-slate-400">Status</span>
                    <EditableTextField type="combobox" v-model="contract.status" :options="statusEditOptions"
                        :readonly="!hasPermission('maintenancecontract.update')" :decoration="false"
                        @update="val => updateContractStatus(contract, val)">
                        <template #display>
                            <span :class="['inline-flex items-center rounded px-2 py-0.5 text-xs font-medium border whitespace-nowrap', maintenanceContractStatusClasses(contract.status)]">
                                {{ maintenanceContractStatusText(contract.status) }}
                            </span>
                        </template>
                    </EditableTextField>
                </div>
                <div class="absolute right-4 top-4 md:static md:col-span-1 md:flex md:justify-end">
                    <div v-if="hasPermission('maintenancecontract.delete')" class="border-1 border-lavoro-darkergray rounded-full p-2">
                        <TrashIcon class="h-5 w-5 cursor-pointer text-red-500" @click="deleteContract(contract.id)" />
                    </div>
                </div>
            </div>
        </div>
        <div v-else class="p-6 text-center">
            <div class="text-gray-400">
                <ClipboardDocumentCheckIcon class="h-12 w-12 mx-auto mb-3" />
                <p class="text-sm">Geen onderhoudscontracten gevonden</p>
            </div>
        </div>
    </BoxComponent>

    <PaginationComponent v-if="(maintenanceContracts.links || []).length" :paginator="maintenanceContracts"
        :params="{ search: searchParam, ...filterParams }" class="border-t border-gray-200 dark:border-slate-700/60" />

    <MaintenanceContractCreateDrawer v-model="showCreateDrawer" :customers="allCustomers"
        :customers-use-ajax="customersUseAjax" :templates="contractTemplates"
        :contract-interval-options="contractIntervalOptions" />
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import { ClipboardDocumentCheckIcon, TrashIcon } from '@heroicons/vue/24/outline'
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue'
import BoxComponent from '@/Components/BoxComponent.vue'
import ComboBox from '@/Components/UI/ComboBox.vue'
import EditableTextField from '@/Components/UI/EditableTextField.vue'
import PaginationComponent from '@/Components/UI/PaginationComponent.vue'
import MaintenanceContractCreateDrawer from '@/Components/MaintenanceContracts/MaintenanceContractCreateDrawer.vue'
import { useComboSearch } from '@/Composables/useComboSearch'
import { hasPermission, nlDate, nlCurrency, maintenanceContractStatusText, maintenanceContractStatusClasses } from '@/Utilities/Utilities'

const props = defineProps({
    maintenanceContracts: { type: Object, required: true },
    allCustomers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    contractIntervalOptions: { type: Array, default: () => [] },
    contractTemplates: { type: Array, default: () => [] },
    search: { type: String, default: '' },
    onlyStatus: { type: String, default: '' },
})

const searchParam = props.search

const showCreateDrawer = ref(false)

const { options: customerOptions, searching: customersSearching, search: searchCustomers } =
    useComboSearch('customers', props.allCustomers, props.customersUseAjax)

const statusFilterOptions = [
    { id: 'actief', name: 'Actief' },
    { id: 'toekomstig', name: 'Toekomstig' },
    { id: 'verlopen', name: 'Verlopen' },
    { id: 'geannuleerd', name: 'Geannuleerd' },
]
// Only the two states someone can actually set from the UI -- toekomstig/verlopen
// are always date-derived, never a real user choice.
const statusEditOptions = [
    { id: 'actief', name: 'Actief' },
    { id: 'geannuleerd', name: 'Geannuleerd' },
]

const urlParams = new URLSearchParams(window.location.search)
const customerFilter = ref(urlParams.get('customer_id') ? Number(urlParams.get('customer_id')) : null)
const statusFilter = ref(props.onlyStatus || null)

const filterParams = computed(() => ({
    customer_id: customerFilter.value || undefined,
    onlyStatus: statusFilter.value || undefined,
}))

function applyFilters() {
    router.get('/maintenancecontracts', { ...filterParams.value, search: searchParam || undefined }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

watch(customerFilter, applyFilters)
watch(statusFilter, applyFilters)

function updateContractStatus(contract, value) {
    router.patch(`/maintenancecontracts/${contract.id}`, { cancelled: value === 'geannuleerd' }, {
        preserveScroll: true,
        preserveState: true,
    })
}

function deleteContract(id) {
    if (!confirm('Weet je zeker dat je dit onderhoudscontract wilt verwijderen?')) return
    useForm({}).delete(`/maintenancecontracts/${id}`, { preserveScroll: true, preserveState: true })
}
</script>
