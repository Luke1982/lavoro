<template>
    <TwoThirdsOneThird>
        <template #main>
            <BoxComponent>
                <div class="flex flex-col md:flex-row md:items-start md:justify-between">
                    <div class="flex items-start">
                        <BuildingOffice2Icon
                            class="size-12 flex-none rounded-lg bg-white dark:bg-slate-800 object-cover ring-1 ring-gray-900/10 dark:ring-slate-600 p-2 mr-4 text-gray-700 dark:text-slate-200" />
                        <div class="flex flex-col">
                            <h1 class="text-xl font-semibold dark:text-slate-100">{{ customer.name }}</h1>
                            <div
                                class="flex flex-wrap items-center gap-x-2 text-sm text-gray-500 dark:text-slate-400 mt-1">
                                <a v-if="customer.website" :href="customer.website" target="_blank" class="underline">{{
                                    customer.website }}</a>
                                <span v-if="customer.website && customer.email"
                                    class="text-gray-300 dark:text-slate-600">|</span>
                                <a v-if="customer.email" :href="`mailto:${customer.email}`" class="underline">{{
                                    customer.email }}</a>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 md:mt-0 md:min-w-60 w-full md:w-auto" v-if="canUpdate">
                        <ComboBox :options="allCustomers" v-model="form.billing_customer_id" label="Factuurklant"
                            placeholder="Kies naar welke klant de factuur moet" @update:modelValue="updateCustomer" />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mt-6">
                    <div>
                        <h3 class="text-xs font-bold mb-2 uppercase tracking-wide text-gray-700 dark:text-slate-300">
                            Bezoekadres</h3>
                        <p class="text-sm text-gray-800 dark:text-slate-300 leading-snug">{{ customer.address }}<br>{{
                            customer.postal_code
                        }}<span v-if="customer.city">,</span> {{ customer.city }}</p>
                    </div>
                    <div>
                        <h3 class="text-xs font-bold mb-2 uppercase tracking-wide text-gray-700 dark:text-slate-300">
                            Postadres</h3>
                        <p class="text-sm text-gray-800 dark:text-slate-300 leading-snug">{{ customer.postal_address
                        }}<br>{{
                                customer.postal_postal_code }}<span v-if="customer.postal_city">,</span> {{
                                customer.postal_city }}</p>
                    </div>
                </div>
            </BoxComponent>
            <div class="mt-5 px-1">
                <div class="flex flex-col md:flex-row md:items-start md:gap-4">
                    <div class="w-full md:w-72">
                        <ComboBox :options="productTypeOptions" v-model="selectedProductTypeIds" multiple
                            placeholder="Filter apparaat type" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2 mt-3 md:mt-0">
                        <template v-if="selectedProductTypeIds.length">
                            <span v-for="pt in selectedProductTypes" :key="pt.id"
                                class="inline-flex items-center gap-1 bg-pink-100 dark:bg-pink-900/40 text-pink-800 dark:text-pink-300 px-2 py-0.5 rounded text-xs font-medium">
                                {{ pt.name }}
                                <button type="button" class="hover:text-pink-600 dark:hover:text-pink-400"
                                    @click="removeProductType(pt.id)">×</button>
                            </span>
                            <button type="button" class="text-xs text-gray-600 dark:text-slate-400 underline"
                                @click="resetFilters">Reset</button>
                        </template>
                        <span v-else class="text-xs text-gray-500 dark:text-slate-400">Alle apparaat types</span>
                    </div>
                </div>
            </div>
            <div class="mt-4" v-if="canReadAssets && hasUpcomingFiltered">
                <div
                    class="bg-white dark:bg-slate-900 rounded-md border border-gray-200 dark:border-slate-700/60 flex items-center justify-between px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded bg-pink-50 dark:bg-pink-900/30 border border-pink-200 dark:border-pink-700/50"><span
                                class="w-2 h-2 rounded-full bg-pink-600"></span></span>
                        <button type="button" class="text-sm font-medium text-gray-800 dark:text-slate-200"
                            @click="showUpcoming = !showUpcoming">Apparaten die binnen
                            30 dagen verlopen</button>
                    </div>
                    <button type="button"
                        class="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-400"
                        @click="showUpcoming = !showUpcoming">…</button>
                </div>
                <transition name="fade-height" mode="out-in">
                    <div v-if="showUpcoming" key="upcoming" class="pt-6">
                        <AssetListGroupComponent :assetGroups="upcomingFiltered" />
                    </div>
                </transition>
            </div>
            <div class="mt-8" v-if="canReadAssets && hasNonUpcomingFiltered">
                <div
                    class="bg-white dark:bg-slate-900 rounded-md border border-gray-200 dark:border-slate-700/60 flex items-center justify-between px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-700/50"><span
                                class="w-2 h-2 rounded-full bg-yellow-500"></span></span>
                        <button type="button" class="text-sm font-medium text-gray-800 dark:text-slate-200"
                            @click="showNonUpcoming = !showNonUpcoming">Apparaten die
                            na 30 dagen verlopen</button>
                    </div>
                    <button type="button"
                        class="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-400"
                        @click="showNonUpcoming = !showNonUpcoming">…</button>
                </div>
                <transition name="fade-height" mode="out-in">
                    <div v-if="showNonUpcoming" key="nonupcoming" class="pt-6">
                        <AssetListGroupComponent :assetGroups="nonUpcomingFiltered" />
                    </div>
                </transition>
            </div>
            <div class="mt-8" v-if="canReadAssets && hasOverdueFiltered">
                <div
                    class="bg-white dark:bg-slate-900 rounded-md border border-gray-200 dark:border-slate-700/60 flex items-center justify-between px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700/50"><span
                                class="w-2 h-2 rounded-full bg-red-500"></span></span>
                        <button type="button" class="text-sm font-medium text-gray-800 dark:text-slate-200"
                            @click="showOverdue = !showOverdue">Apparaten met verlopen of ontbrekende service
                            datum</button>
                    </div>
                    <button type="button"
                        class="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-400"
                        @click="showOverdue = !showOverdue">…</button>
                </div>
                <transition name="fade-height" mode="out-in">
                    <div v-if="showOverdue" key="overdue" class="pt-6">
                        <AssetListGroupComponent :assetGroups="overdueFiltered" />
                    </div>
                </transition>
            </div>
            <AddAssetForm :customerId="customer.id" :allProducts="allProducts" v-if="hasPermission('asset.create')" />

        </template>

        <template #sidebar>
            <div class="space-y-4 md:ml-5 md:mt-0 ml-0 mt-5">

                <BoxComponent>
                    <div class="flex mb-4 border-b-1 border-gray-200 pb-2 justify-between">
                        <div class="flex">
                            <ClipboardDocumentListIcon class="size-6 flex-none text-gray-500 mr-2" />
                            <h2 class="font-regular text-xl">Werkbonnen</h2>
                        </div>
                        <PlusCircleIcon v-if="canCreateServiceOrder"
                            class="size-6 flex-none text-green-500 cursor-pointer hover:text-green-700"
                            @click="newServiceOrderForm.post(`/serviceorders`, { preserveScroll: true })"
                            v-tooltip="`Maak een nieuwe werkbon aan voor ${customer.name}`" />
                    </div>
                    <ServiceOrderRow v-for="serviceorder in customer.service_orders" v-bind:key="serviceorder.id"
                        :serviceorder="serviceorder" />
                </BoxComponent>
                <BoxComponent class="bg-white dark:bg-slate-900 border border-gray-200 dark:border-slate-700/60">
                    <div
                        class="flex mb-4 border-b-1 border-gray-200 dark:border-slate-700/60 pb-2 justify-between items-center">
                        <div class="flex items-center">
                            <svg class="size-6 text-gray-500 dark:text-slate-400 mr-2" fill="none" stroke="currentColor"
                                stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <h2 class="font-regular text-xl text-gray-800 dark:text-slate-200">Afspraken</h2>
                        </div>
                    </div>
                    <EventTimelineComponent :events="eventList" />
                </BoxComponent>
            </div>
        </template>
    </TwoThirdsOneThird>
</template>

<script setup>
import '@/Layouts/TwoThirdsOneThird.vue';
import '@/Components/BoxComponent.vue';
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import { BuildingOffice2Icon, ClipboardDocumentListIcon, PlusCircleIcon } from '@heroicons/vue/24/outline';
import BoxComponent from '@/Components/BoxComponent.vue';
import AssetListGroupComponent from '@/Components/AssetListGroupComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import { useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { hasPermission } from '@/Utilities/Utilities';
import ServiceOrderRow from '@/Components/ServiceOrderRow.vue';
import EventTimelineComponent from '@/Components/Timeline/EventTimelineComponent.vue';
import AddAssetForm from '@/Components/AddAssetForm.vue';

const props = defineProps({
    customer: {
        type: Object,
        required: true,
    },
    upcomingAssetsByType: {
        type: Object,
        required: true,
    },
    nonUpcomingAssetsByType: {
        type: Object,
        required: true,
    },
    overdueAssetsByType: {
        type: Object,
        required: true,
    },
    allCustomers: {
        type: Array,
        required: true,
    },
    allProducts: {
        type: Array,
        required: true,
    }
});

const form = useForm({
    ...props.customer,
    billing_customer_id: props.customer.billing_customer_id || null,
});

const newServiceOrderForm = useForm({
    customer_id: props.customer.id,
});

const canCreateServiceOrder = computed(() => hasPermission('serviceorder.create'));

const canUpdate = computed(() => hasPermission('customer.update'))

const canReadAssets = computed(() => hasPermission('asset.read'))

const updateCustomer = () => {
    form.patch(`/customers/${props.customer.id}`)
};

const showUpcoming = ref(true);
const showNonUpcoming = ref(false);
const showOverdue = ref(true);

// Product type filtering ----------------------------------------------------
const selectedProductTypeIds = ref([]); // array of product_type ids

// Collect unique product types from both upcoming and non-upcoming groups
const productTypeOptions = computed(() => {
    const map = new Map();
    const collect = (groups) => {
        Object.values(groups || {}).forEach(assets => {
            (assets || []).forEach(a => {
                const pt = a?.product?.product_type;
                if (pt && !map.has(pt.id)) map.set(pt.id, { id: pt.id, name: pt.name });
            });
        });
    };
    collect(props.upcomingAssetsByType);
    collect(props.nonUpcomingAssetsByType);
    collect(props.overdueAssetsByType);
    return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name));
});

const selectedProductTypes = computed(() => {
    const optionMap = Object.fromEntries(productTypeOptions.value.map(o => [o.id, o]));
    return selectedProductTypeIds.value.map(id => optionMap[id]).filter(Boolean);
});

const removeProductType = (id) => {
    selectedProductTypeIds.value = selectedProductTypeIds.value.filter(x => x !== id);
};
const resetFilters = () => { selectedProductTypeIds.value = []; };

// Filtering helpers
const filterAssetGroups = (groups) => {
    if (!selectedProductTypeIds.value.length) return groups;
    return Object.entries(groups || {}).reduce((acc, [groupName, assets]) => {
        const filtered = (assets || []).filter(a => selectedProductTypeIds.value.includes(a?.product?.product_type?.id));
        if (filtered.length) acc[groupName] = filtered;
        return acc;
    }, {});
};

const upcomingFiltered = computed(() => filterAssetGroups(props.upcomingAssetsByType));
const nonUpcomingFiltered = computed(() => filterAssetGroups(props.nonUpcomingAssetsByType));
const overdueFiltered = computed(() => filterAssetGroups(props.overdueAssetsByType));

const hasUpcomingFiltered = computed(() => Object.values(upcomingFiltered.value || {}).some(arr => arr.length));
const hasNonUpcomingFiltered = computed(() => Object.values(nonUpcomingFiltered.value || {}).some(arr => arr.length));
const hasOverdueFiltered = computed(() => Object.values(overdueFiltered.value || {}).some(arr => arr.length));

// Auto collapse groups if they become empty
watch(hasUpcomingFiltered, (val) => { if (!val) showUpcoming.value = false; });
watch(hasNonUpcomingFiltered, (val) => { if (!val) showNonUpcoming.value = false; });
watch(hasOverdueFiltered, (val) => { if (!val) showOverdue.value = false; });

// Collect all events from service orders for this customer for timeline
const eventList = computed(() => {
    const orders = props.customer.service_orders || [];
    return orders.flatMap(o => (o.events || []).map(e => ({
        ...e,
        service_order_id: o.id,
    })));
});
</script>

<style scoped>
.fade-height-enter-active,
.fade-height-leave-active {
    transition: opacity .25s ease, max-height .25s ease, transform .25s ease;
}

.fade-height-enter-from,
.fade-height-leave-to {
    opacity: 0;
    max-height: 0;
    transform: translateY(-6px);
}

.fade-height-enter-to,
.fade-height-leave-from {
    opacity: 1;
    max-height: 2000px;
    transform: translateY(0);
}
</style>