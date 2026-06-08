<template>
    <IndexHeaderComponent title="Machines" subtitle="Zoek en filter machines"
        search-placeholder="Zoek op serienummer, merk, model, soort of klant" search-url="/assets" :paginator="assets"
        :add-label="canCreate ? 'Voeg machine toe' : ''" @add="() => canCreate && (addAssetDrawerOpen = true)"
        :has-active-filters="activeFilters.length > 0">
        <template #filters>
            <div class="flex flex-col sm:flex-row gap-4 w-full">
                <div class="flex-1">
                    <label class="block text-sm font-medium mb-2">Filter op status</label>
                    <ComboBox :options="statusOptions" v-model="selectedStatus" placeholder="Laat alleen status zien"
                        class="w-full" @update:modelValue="val => { updateLocalStorageStatus(val) }" />
                </div>
                <div class="flex-1">
                    <label class="block text-sm font-medium mb-2">Filter op tickets</label>
                    <ComboBox :options="ticketFilterOptions" v-model="selectedTicketFilter"
                        placeholder="Filter op tickets" class="w-full"
                        @update:modelValue="val => localStorage.setItem('selectedAssetTicketFilter', val)" />
                </div>
                <div class="hidden sm:flex items-end justify-end text-lavoro-blue font-semibold text-sm cursor-pointer pb-0.5"
                    @click="clearAllFilters">
                    <RotateCcwIcon class="h-5 w-5 mr-1" />Wis filters
                </div>
            </div>
            <div v-if="activeFilters.length" class="flex flex-wrap gap-2 mt-3" v-auto-animate>
                <span v-for="filter in activeFilters" :key="filter.key"
                    class="inline-flex items-center gap-x-1.5 rounded-md px-3 py-2 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 bg-white dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700">
                    <span class="text-gray-400 dark:text-slate-400">{{ filter.label }}:</span>
                    {{ filter.value }}
                    <button type="button" @click="filter.clear()"
                        class="group relative -mr-1 h-3.5 w-3.5 rounded-sm hover:bg-gray-500/20">
                        <span class="sr-only">Verwijder filter</span>
                        <svg viewBox="0 0 14 14"
                            class="h-3.5 w-3.5 stroke-gray-600/75 group-hover:stroke-gray-600 dark:stroke-slate-400 dark:group-hover:stroke-slate-300">
                            <path d="M4 4l6 6m0-6l-6 6" />
                        </svg>
                        <span class="absolute -inset-1" />
                    </button>
                </span>
                <div class="flex sm:hidden p-2 items-end justify-end text-lavoro-blue font-semibold text-sm cursor-pointer"
                    @click="clearAllFilters">
                    <RotateCcwIcon class="h-5 w-5 mr-1" />Wis filters
                </div>
            </div>
        </template>
    </IndexHeaderComponent>
    <DrawerComponent v-if="canCreate" v-model="addAssetDrawerOpen" title="Nieuwe machine toevoegen"
        subtitle="Vul onderstaande velden in om een nieuwe machine toe te voegen.">
        <div class="divide-y divide-gray-200 dark:divide-slate-700">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Product</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="productOptions" v-model="newAssetForm.product_id"
                        placeholder="Selecteer product" :has-external-searching="productsUseAjax"
                        :searching="productSearching" @change="searchProducts"
                        :hasError="Boolean(newAssetForm.errors.product_id)"
                        :errorMessage="newAssetForm.errors.product_id" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Klant</label>
                <div class="sm:col-span-2">
                    <ComboBox :options="customerOptions" v-model="newAssetForm.customer_id"
                        placeholder="Selecteer klant" :has-external-searching="customersUseAjax"
                        :searching="customerSearching" @change="searchCustomers"
                        :hasError="Boolean(newAssetForm.errors.customer_id)"
                        :errorMessage="newAssetForm.errors.customer_id" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Serienummer</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newAssetForm.serial_number"
                        :placeholder="isNewBundle ? 'Bundel — geen serienummer' : 'Serienummer'" :disabled="isNewBundle"
                        :hasError="Boolean(newAssetForm.errors.serial_number)"
                        :errorMessage="newAssetForm.errors.serial_number" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">In gebruikname</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newAssetForm.date_in_service" type="date"
                        :hasError="Boolean(newAssetForm.errors.date_in_service)"
                        :errorMessage="newAssetForm.errors.date_in_service" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Volgende keuring</label>
                <div class="sm:col-span-2">
                    <TextInput v-model="newAssetForm.next_service_date" type="date"
                        :hasError="Boolean(newAssetForm.errors.next_service_date)"
                        :errorMessage="newAssetForm.errors.next_service_date" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-4 sm:px-6 py-4 sm:items-center">
                <label class="text-sm font-bold text-gray-900 dark:text-slate-200">Actief</label>
                <div class="sm:col-span-2">
                    <SwitchComponent v-model="newAssetForm.is_active" />
                </div>
            </div>
        </div>
        <template #footer>
            <div class="flex justify-end gap-2">
                <button type="button" @click="closeAssetDrawer"
                    class="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700">
                    Annuleren
                </button>
                <button type="button" @click="submitAsset" :disabled="newAssetForm.processing"
                    class="px-4 py-2 text-sm font-medium bg-lavoro-blue text-white rounded-md hover:opacity-90 disabled:opacity-60 disabled:cursor-not-allowed">
                    Toevoegen
                </button>
            </div>
        </template>
    </DrawerComponent>

    <ModalDialog v-model:open="showChildModal" title="Vereiste serienummers" max-width-class="sm:max-w-lg">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Dit product vereist de volgende onderdelen. Voer voor elk onderdeel het serienummer in.
        </p>
        <div class="mt-4 space-y-4">
            <div v-for="(child, index) in pendingChildren" :key="index">
                <TextInput v-model="child.serial_number" :label="`${child.relation_name}: ${child.name}`"
                    placeholder="Serienummer" />
            </div>
        </div>
        <template #footer>
            <div class="flex gap-3 justify-end">
                <button type="button"
                    class="rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-700"
                    @click="cancelChildModal">
                    Annuleren
                </button>
                <button type="button"
                    class="inline-flex justify-center rounded-md bg-lavoro-blue px-3 py-2 text-sm font-semibold text-white shadow-xs hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2"
                    @click="confirmChildModal">
                    Toevoegen
                </button>
            </div>
        </template>
    </ModalDialog>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div
            class="hidden lg:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray rounded-t-lavoro-sm p-4 bg-lavoro-lightgray">
            <div class="col-span-3">Machine</div>
            <div class="col-span-2">Serienummer</div>
            <div class="col-span-1">In dienst</div>
            <div class="col-span-2">Volgende keuring</div>
            <div class="col-span-1">Status</div>
            <div class="col-span-1 text-center">Open</div>
            <div class="col-span-1 text-center">Lopend</div>
            <div class="col-span-1 text-center">Gesloten</div>
        </div>
        <div v-auto-animate>
            <div v-for="asset in filteredAssets" :key="asset.id" role="row"
                class="relative grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 border-b-2 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors duration-300">
                <div class="col-span-10 lg:col-span-3 flex items-center gap-3">
                    <img class="size-10 flex-none rounded-full bg-gray-50 object-cover"
                        :src="asset.product.images.length > 0 ? `/storage/${asset.product.images[0].path}` : '/img/placeholder.png'"
                        alt="" />
                    <div class="min-w-0">
                        <Link :href="`/assets/${asset.id}`"
                            class="font-semibold text-gray-900 dark:text-gray-200 hover:underline">
                            <span class="absolute inset-0" />
                            {{ asset.product.brand.name }} {{ asset.product.model }}
                        </Link>
                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate">
                            {{ asset.product.product_type.name }} &middot;
                            <Link :href="`/customers/${asset.customer.id}`" class="relative underline">{{
                                asset.customer.name }}</Link>
                        </div>
                    </div>
                </div>
                <div class="col-span-2 hidden lg:flex items-center text-gray-600 dark:text-gray-300">
                    {{ asset.serial_number || '—' }}
                </div>
                <div class="col-span-1 hidden lg:flex items-center text-gray-500 dark:text-gray-400 text-xs">
                    {{ asset.date_in_service ? nlDate(asset.date_in_service) : '—' }}
                </div>
                <div class="col-span-2 hidden lg:flex items-center gap-1 text-xs">
                    <CalendarDateRangeIcon v-if="asset.next_service_date" class="size-4 text-gray-400 flex-none"
                        aria-hidden="true" />
                    <span
                        :class="asset.next_service_date && new Date(asset.next_service_date) < new Date() ? 'text-red-600 font-medium' : 'text-gray-500 dark:text-gray-400'">
                        {{ asset.next_service_date ? nlDate(asset.next_service_date) : '—' }}
                    </span>
                </div>
                <div class="col-span-1 hidden lg:flex items-center">
                    <span v-if="asset.status === 'Actief'"
                        class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset">Actief</span>
                    <span v-else
                        class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-red-600/10 ring-inset">{{
                            asset.status }}</span>
                </div>
                <div class="col-span-1 hidden lg:flex items-center justify-center">
                    <span v-if="asset.open_tickets_count > 0"
                        class="inline-flex items-center justify-center rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-blue-600/20 ring-inset min-w-[1.75rem]">
                        {{ asset.open_tickets_count }}
                    </span>
                    <span v-else class="text-gray-300 text-xs">—</span>
                </div>
                <div class="col-span-1 hidden lg:flex items-center justify-center">
                    <span v-if="asset.pending_tickets_count > 0"
                        class="inline-flex items-center justify-center rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-600/20 ring-inset min-w-[1.75rem]">
                        {{ asset.pending_tickets_count }}
                    </span>
                    <span v-else class="text-gray-300 text-xs">—</span>
                </div>
                <div class="col-span-1 hidden lg:flex items-center justify-center">
                    <span v-if="asset.closed_tickets_count > 0"
                        class="inline-flex items-center justify-center rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-gray-400/20 ring-inset min-w-[1.75rem]">
                        {{ asset.closed_tickets_count }}
                    </span>
                    <span v-else class="text-gray-300 text-xs">—</span>
                </div>
                <div class="col-span-2 lg:hidden flex flex-col gap-1 items-end justify-center">
                    <span v-if="asset.status === 'Actief'"
                        class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset">Actief</span>
                    <span v-else
                        class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-red-600/10 ring-inset">{{
                            asset.status }}</span>
                    <span class="text-xs text-gray-400">
                        {{ asset.next_service_date ? nlDate(asset.next_service_date) : '' }}
                    </span>
                </div>
                <ChevronRightIcon class="absolute right-4 top-1/2 -translate-y-1/2 size-5 text-gray-400"
                    aria-hidden="true" />
            </div>
        </div>
        <div class="flex justify-between bg-white rounded-b-lavoro-sm p-4">
            <PageRecordCountComponent :total="assets.total" :per-page="perPage" label="machines" />
            <PaginationComponent v-if="assets.data.length" :paginator="assets" />
        </div>
    </BoxComponent>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { ChevronRightIcon } from '@heroicons/vue/20/solid';
import { CalendarDateRangeIcon } from '@heroicons/vue/24/outline';
import { RotateCcwIcon } from '@lucide/vue';
import { Link, useForm } from '@inertiajs/vue3';
import ComboBox from '@/Components/UI/ComboBox.vue';
import BoxComponent from '@/Components/BoxComponent.vue';
import DrawerComponent from '@/Components/UI/DrawerComponent.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import ModalDialog from '@/Components/UI/ModalDialog.vue';
import SwitchComponent from '@/Components/UI/SwitchComponent.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { hasPermission, todayIso, nextServiceIso, nlDate } from '@/Utilities/Utilities';
import { useComboSearch } from '@/Composables/useComboSearch';
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue';
import PaginationComponent from '@/Components/UI/PaginationComponent.vue';

const addAssetDrawerOpen = ref(false)

const props = defineProps({
    assets: {
        type: Object,
        required: true,
    },
    allProducts: { type: Array, default: () => [] },
    productsUseAjax: { type: Boolean, default: false },
    allCustomers: { type: Array, default: () => [] },
    customersUseAjax: { type: Boolean, default: false },
    requiredProductablesByProduct: { type: Object, default: () => ({}) },
    perPage: { type: Number, default: 20 },
});

const { options: productOptions, searching: productSearching, search: searchProducts } =
    useComboSearch('products', props.allProducts, props.productsUseAjax)

// no per-page search state needed; SearchComponent handles it

const selectedStatus = ref(Number(localStorage.getItem('selectedAssetStatus')) || 1);
const selectedTicketFilter = ref(Number(localStorage.getItem('selectedAssetTicketFilter')) || 1);

const filteredAssets = computed(() => {
    return props.assets.data.filter(asset => {
        if (selectedStatus.value === 2 && asset.status !== 'Actief') return false;
        if (selectedStatus.value === 3 && asset.status !== 'Niet actief') return false;

        const total = asset.open_tickets_count + asset.pending_tickets_count + asset.closed_tickets_count;
        if (selectedTicketFilter.value === 2 && total === 0) return false;
        if (selectedTicketFilter.value === 3 && asset.open_tickets_count === 0) return false;
        if (selectedTicketFilter.value === 4 && asset.pending_tickets_count === 0) return false;

        return true;
    });
});

const statusOptions = [
    { id: 1, name: 'Alle statussen' },
    { id: 2, name: 'Actief' },
    { id: 3, name: 'Niet actief' },
];

const ticketFilterOptions = [
    { id: 1, name: 'Alle machines' },
    { id: 2, name: 'Heeft tickets' },
    { id: 3, name: 'Heeft open tickets' },
    { id: 4, name: 'Heeft lopende tickets' },
];

const activeFilters = computed(() => {
    const filters = []
    if (selectedStatus.value !== 1) {
        const match = statusOptions.find(o => o.id === selectedStatus.value)
        if (match) filters.push({
            key: 'status',
            label: 'Status',
            value: match.name,
            clear: () => { selectedStatus.value = 1; localStorage.setItem('selectedAssetStatus', 1) },
        })
    }
    if (selectedTicketFilter.value !== 1) {
        const match = ticketFilterOptions.find(o => o.id === selectedTicketFilter.value)
        if (match) filters.push({
            key: 'tickets',
            label: 'Tickets',
            value: match.name,
            clear: () => { selectedTicketFilter.value = 1; localStorage.setItem('selectedAssetTicketFilter', 1) },
        })
    }
    return filters
})

function updateLocalStorageStatus(val) {
    localStorage.setItem('selectedAssetStatus', val);
}

function clearAllFilters() {
    selectedStatus.value = 1;
    selectedTicketFilter.value = 1;
    localStorage.setItem('selectedAssetStatus', 1);
    localStorage.setItem('selectedAssetTicketFilter', 1);
}

const { options: customerOptions, searching: customerSearching, search: searchCustomers } =
    useComboSearch('customers', props.allCustomers, props.customersUseAjax)

const newAssetForm = useForm({
    product_id: null,
    customer_id: null,
    serial_number: '',
    date_in_service: todayIso(),
    next_service_date: '',
    is_active: true,
    child_assets: [],
})

const isNewBundle = computed(() => {
    const product = productOptions.value.find(p => p.id === newAssetForm.product_id)
    return product?.bundle === true
})

watch(() => newAssetForm.product_id, (productId) => {
    const product = productOptions.value.find(p => p.id === productId)
    newAssetForm.next_service_date = nextServiceIso(product) ?? ''
})

function closeAssetDrawer() {
    addAssetDrawerOpen.value = false
    newAssetForm.reset()
    newAssetForm.clearErrors()
}

const canCreate = computed(() => hasPermission('asset.create'))

const showChildModal = ref(false)
const pendingChildren = ref([])
let resolveChildModal = null

async function submitAsset() {
    const required = props.requiredProductablesByProduct[newAssetForm.product_id]
    if (required && required.length > 0) {
        pendingChildren.value = required.flatMap(item =>
            Array.from({ length: item.quantity }, () => ({
                productable_id: item.productable_id,
                name: item.name,
                relation_name: item.relation_name,
                serial_number: '',
            }))
        )
        showChildModal.value = true
        const extra = await new Promise((resolve) => { resolveChildModal = resolve })
        if (!extra) return
        newAssetForm.child_assets = extra.child_assets
    }

    newAssetForm.post('/assets', {
        preserveScroll: true,
        onSuccess: () => closeAssetDrawer(),
    })
}

function confirmChildModal() {
    if (resolveChildModal) {
        resolveChildModal({
            child_assets: pendingChildren.value.map(c => ({
                productable_id: c.productable_id,
                serial_number: c.serial_number,
            })),
        })
        resolveChildModal = null
    }
    showChildModal.value = false
}

function cancelChildModal() {
    if (resolveChildModal) {
        resolveChildModal(false)
        resolveChildModal = null
    }
    showChildModal.value = false
}
</script>