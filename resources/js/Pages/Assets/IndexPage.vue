<template>
    <IndexHeaderComponent ref="header" title="Machines" subtitle="Zoek en filter machines"
        search-placeholder="Zoek op serienummer, merk, model, soort of klant" search-url="/assets" :paginator="assets"
        :add-label="canCreate ? 'Voeg machine toe' : ''" @add="() => canCreate && (addAssetDrawerOpen = true)"
        :search-other-params="filterParams" :has-active-filters="activeFilters.length > 0">
        <template #actions>
            <TourButton tour="machines" data-tour="machines.tour" />
        </template>
        <template #filters>
            <div class="flex flex-col sm:flex-row gap-4 w-full">
                <div class="flex-1" data-tour="machines.filter.status">
                    <label class="block text-sm font-medium mb-2">Filter op status</label>
                    <ComboBox :options="FILTERS.status.options" v-model="chosen.status" placeholder="Laat alleen status zien"
                        class="w-full" />
                </div>
                <div class="flex-1" data-tour="machines.filter.tickets">
                    <label class="block text-sm font-medium mb-2">Filter op storingen</label>
                    <ComboBox :options="FILTERS.tickets.options" v-model="chosen.tickets"
                        placeholder="Filter op storingen" class="w-full" />
                </div>
                <div class="hidden sm:flex items-end justify-end text-lavoro-blue dark:text-blue-400 font-semibold text-sm cursor-pointer pb-0.5"
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
                <div class="flex sm:hidden p-2 items-end justify-end text-lavoro-blue dark:text-blue-400 font-semibold text-sm cursor-pointer"
                    @click="clearAllFilters">
                    <RotateCcwIcon class="h-5 w-5 mr-1" />Wis filters
                </div>
            </div>
        </template>
    </IndexHeaderComponent>
    <AssetCreateDrawer v-model="addAssetDrawerOpen" :all-products="allProducts" :products-use-ajax="productsUseAjax"
        :all-customers="allCustomers" :customers-use-ajax="customersUseAjax"
        :bundle-parts-by-product="bundlePartsByProduct" />

    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <div
            class="hidden lg:grid grid-cols-12 font-bold text-sm border-b-lavoro-darkergray dark:border-b-slate-600 rounded-t-lavoro-sm p-4 bg-lavoro-lightgray dark:bg-slate-800/50">
            <div class="col-span-3">Machine</div>
            <div class="col-span-2">Serienummer</div>
            <div class="col-span-1">In dienst</div>
            <div class="col-span-2">Volgende keuring</div>
            <div class="col-span-1">Status</div>
            <div class="col-span-1 text-center">Open</div>
            <div class="col-span-1 text-center">Lopend</div>
            <div class="col-span-1 text-center">Gesloten</div>
        </div>
        <div ref="rows" v-auto-animate>
            <div v-for="asset in assets.data" :key="asset.id" role="row"
                class="relative grid grid-cols-12 p-4 text-sm border-b-lavoro-gray-150 dark:border-b-slate-800 border-b-2 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors duration-300">
                <div class="col-span-10 lg:col-span-3 flex items-center gap-3">
                    <img class="size-10 flex-none rounded-full bg-gray-50 dark:bg-slate-800/50 object-cover"
                        :src="asset.product.images.length > 0 ? imageFileUrl(asset.product.images[0]) : '/img/placeholder.png'"
                        alt="" />
                    <div class="min-w-0">
                        <Link :href="`/assets/${asset.id}`"
                            class="font-semibold text-gray-900 dark:text-gray-200 hover:underline">
                            <span class="absolute inset-0" />
                            {{ asset.product.brand.name }} {{ asset.product.model }}
                        </Link>
                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate">
                            {{ asset.product.product_type.name }}
                            <template v-if="asset.owning_customer">
                                &middot;
                                <Link :href="`/customers/${asset.owning_customer.id}`" class="relative underline">{{
                                    asset.owning_customer.name }}</Link>
                            </template>
                        </div>
                    </div>
                </div>
                <div class="col-span-2 hidden lg:flex items-center text-gray-600 dark:text-gray-300" data-cell="serial">
                    {{ asset.serial_number || '—' }}
                </div>
                <div class="col-span-1 hidden lg:flex items-center text-gray-500 dark:text-gray-400 text-xs">
                    {{ asset.date_in_service ? nlDate(asset.date_in_service) : '—' }}
                </div>
                <div class="col-span-2 hidden lg:flex items-center gap-1 text-xs" data-cell="next-service">
                    <CalendarDateRangeIcon v-if="asset.next_service_date" class="size-4 text-gray-400 flex-none"
                        aria-hidden="true" />
                    <span
                        :class="asset.next_service_date && new Date(asset.next_service_date) < new Date() ? 'text-red-600 dark:text-red-400 font-medium' : 'text-gray-500 dark:text-gray-400'">
                        {{ asset.next_service_date ? nlDate(asset.next_service_date) : '—' }}
                    </span>
                </div>
                <div class="col-span-1 hidden lg:flex items-center">
                    <span v-if="asset.status === 'Actief'"
                        class="inline-flex items-center rounded-full bg-green-50 dark:bg-green-900/20 px-2 py-1 text-xs font-medium text-green-700 dark:text-green-300 ring-1 ring-green-600/20 dark:ring-green-500/30 ring-inset">Actief</span>
                    <span v-else
                        class="inline-flex items-center rounded-full bg-red-50 dark:bg-red-900/20 px-2 py-1 text-xs font-medium text-red-700 dark:text-red-300 ring-1 ring-red-600/10 dark:ring-red-500/30 ring-inset">{{
                            asset.status }}</span>
                </div>
                <div class="col-span-1 hidden lg:flex items-center justify-center" data-cell="tickets">
                    <span v-if="asset.open_tickets_count > 0"
                        class="inline-flex items-center justify-center rounded-full bg-blue-50 dark:bg-blue-900/20 px-2 py-1 text-xs font-medium text-blue-700 dark:text-blue-300 ring-1 ring-blue-600/20 dark:ring-blue-500/30 ring-inset min-w-[1.75rem]">
                        {{ asset.open_tickets_count }}
                    </span>
                    <span v-else class="text-gray-300 text-xs">—</span>
                </div>
                <div class="col-span-1 hidden lg:flex items-center justify-center" data-cell="tickets">
                    <span v-if="asset.pending_tickets_count > 0"
                        class="inline-flex items-center justify-center rounded-full bg-amber-50 dark:bg-amber-900/20 px-2 py-1 text-xs font-medium text-amber-700 dark:text-amber-300 ring-1 ring-amber-600/20 dark:ring-amber-500/30 ring-inset min-w-[1.75rem]">
                        {{ asset.pending_tickets_count }}
                    </span>
                    <span v-else class="text-gray-300 text-xs">—</span>
                </div>
                <div class="col-span-1 hidden lg:flex items-center justify-center" data-cell="tickets">
                    <span v-if="asset.closed_tickets_count > 0"
                        class="inline-flex items-center justify-center rounded-full bg-gray-100 dark:bg-slate-800 px-2 py-1 text-xs font-medium text-gray-600 dark:text-slate-400 ring-1 ring-gray-400/20 ring-inset min-w-[1.75rem]">
                        {{ asset.closed_tickets_count }}
                    </span>
                    <span v-else class="text-gray-300 text-xs">—</span>
                </div>
                <div class="col-span-2 lg:hidden flex flex-col gap-1 items-end justify-center">
                    <span v-if="asset.status === 'Actief'"
                        class="inline-flex items-center rounded-full bg-green-50 dark:bg-green-900/20 px-2 py-1 text-xs font-medium text-green-700 dark:text-green-300 ring-1 ring-green-600/20 dark:ring-green-500/30 ring-inset">Actief</span>
                    <span v-else
                        class="inline-flex items-center rounded-full bg-red-50 dark:bg-red-900/20 px-2 py-1 text-xs font-medium text-red-700 dark:text-red-300 ring-1 ring-red-600/10 dark:ring-red-500/30 ring-inset">{{
                            asset.status }}</span>
                    <span class="text-xs text-gray-400">
                        {{ asset.next_service_date ? nlDate(asset.next_service_date) : '' }}
                    </span>
                </div>
                <ChevronRightIcon class="absolute right-4 top-1/2 -translate-y-1/2 size-5 text-gray-400"
                    aria-hidden="true" />
            </div>
        </div>
        <div class="flex justify-between bg-white dark:bg-slate-900 rounded-b-lavoro-sm p-4">
            <PageRecordCountComponent :total="assets.total" :per-page="perPage" label="machines" />
            <PaginationComponent v-if="assets.data.length" :paginator="assets" />
        </div>
    </BoxComponent>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ChevronRightIcon } from '@heroicons/vue/20/solid';
import { CalendarDateRangeIcon } from '@heroicons/vue/24/outline';
import { RotateCcwIcon } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import ComboBox from '@/Components/UI/ComboBox.vue';
import BoxComponent from '@/Components/BoxComponent.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import AssetCreateDrawer from '@/Components/Assets/AssetCreateDrawer.vue';
import { hasPermission, nlDate } from '@/Utilities/Utilities';
import PageRecordCountComponent from '@/Components/UI/PageRecordCountComponent.vue';
import PaginationComponent from '@/Components/UI/PaginationComponent.vue';
import { imageFileUrl } from '@/Utilities/imageFiles.js';
import { opensWithAssistantFilter } from '@/Utilities/assistantFilter';
import TourButton from '@/Components/Tour/TourButton.vue';
import { useTour } from '@/Composables/useTour';

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
    bundlePartsByProduct: { type: Object, default: () => ({}) },
    perPage: { type: Number, default: 20 },
    /** The filters the server applied, over all machines: `status` and `tickets`. */
    filters: { type: Object, default: () => ({}) },
});

const ALL = 'all';

/**
 * What each filter offers, and what its chip says while it is in use. The
 * server applies them over all machines, not only the page on the screen.
 */
const FILTERS = {
    status: {
        chip: 'Status',
        options: [
            { id: ALL, name: 'Alle statussen' },
            { id: 'Actief', name: 'Actief' },
            { id: 'Niet actief', name: 'Niet actief' },
        ],
    },
    tickets: {
        chip: 'Storingen',
        options: [
            { id: ALL, name: 'Alle machines' },
            { id: 'any', name: 'Met storingen' },
            { id: 'open', name: 'Met open storingen' },
            { id: 'pending', name: 'Met lopende storingen' },
        ],
    },
};

const perFilter = value => Object.fromEntries(Object.keys(FILTERS).map(key => [key, value(key)]));

const chosen = reactive(perFilter(key => props.filters[key] ?? ALL));

/** Sent along with every search; a change in them searches again by itself. */
const filterParams = computed(() => perFilter(key => (chosen[key] === ALL ? '' : chosen[key])));

const activeFilters = computed(() => Object.entries(FILTERS)
    .filter(([key]) => chosen[key] !== ALL)
    .map(([key, filter]) => ({
        key,
        label: filter.chip,
        value: filter.options.find(option => option.id === chosen[key])?.name,
        clear: () => { chosen[key] = ALL },
    })));

function clearAllFilters() {
    Object.assign(chosen, perFilter(() => ALL));
}

/** This browser remembers them, and brings them back on a visit that has none of its own. */
const storageKey = key => 'assetFilter_' + key;

function remember(key, value) {
    try {
        localStorage.setItem(storageKey(key), value);
    } catch {
        /* a browser that keeps nothing simply starts without filters */
    }
}

function recall(key) {
    try {
        const value = localStorage.getItem(storageKey(key));
        return FILTERS[key].options.some(option => option.id === value) ? value : ALL;
    } catch {
        return ALL;
    }
}

watch(chosen, () => Object.entries(chosen).forEach(([key, value]) => remember(key, value)));

onMounted(() => {
    if (opensWithAssistantFilter() || Object.keys(props.filters).length) return;
    Object.assign(chosen, perFilter(recall));
});

const canCreate = computed(() => hasPermission('asset.create'))

/**
 * The machines rondleiding opens the filters and the new-machine drawer here,
 * and points at the first machine in the list and the cells of that row.
 */
const header = ref(null);
const rows = ref(null);
const firstRow = () => rows.value?.querySelector('[role=row]') ?? null;

useTour('machines', {
    openFilters: () => header.value?.openFilters() ?? null,
    openNewMachine: () => {
        if (!canCreate.value || addAssetDrawerOpen.value) return null;
        addAssetDrawerOpen.value = true;

        return () => { addAssetDrawerOpen.value = false };
    },
}, {
    spots: {
        'machines.row': firstRow,
        'machines.row.serial': () => firstRow()?.querySelector('[data-cell=serial]') ?? null,
        'machines.row.next-service': () => firstRow()?.querySelector('[data-cell=next-service]') ?? null,
        'machines.row.tickets': () => [...(firstRow()?.querySelectorAll('[data-cell=tickets]') ?? [])],
    },
});
</script>