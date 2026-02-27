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
                    <div class="flex items-center gap-4 mt-4 md:mt-0">
                        <div class="md:min-w-60 w-full md:w-auto" v-if="canUpdate">
                            <div class="flex items-end gap-2">
                                <ComboBox :options="allCustomers" v-model="form.billing_customer_id"
                                    label="Factuurklant" placeholder="Kies naar welke klant de factuur moet"
                                    @update:modelValue="updateCustomer" class="grow" />
                                <XCircleIcon v-if="form.billing_customer_id"
                                    class="size-6 mb-1.5 text-gray-400 hover:text-gray-600 cursor-pointer"
                                    @click="clearBillingCustomer" v-tooltip="'Factuurklant leegmaken'" />
                            </div>
                        </div>
                        <Link v-if="canUpdate" :href="`/customers/${customer.id}/edit`"
                            class="text-gray-400 hover:text-gray-600 dark:text-slate-400 dark:hover:text-slate-200">
                        <PencilSquareIcon class="size-6" />
                        </Link>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mt-6">
                    <div>
                        <h2 class="text-base font-semibold dark:text-slate-200">Contactgegevens</h2>
                        <dl class="mt-2 space-y-1 text-sm text-gray-800 dark:text-slate-300">
                            <div v-if="customer.contactname">
                                <dt class="inline font-semibold">Contactpersoon:</dt>
                                <dd class="inline ml-1">{{ customer.contactname }}</dd>
                            </div>
                            <div v-if="customer.phone">
                                <dt class="inline font-semibold">Telefoon:</dt>
                                <dd class="inline ml-1">{{ customer.phone }}</dd>
                            </div>
                            <div v-if="customer.mobile">
                                <dt class="inline font-semibold">Mobiel:</dt>
                                <dd class="inline ml-1">{{ customer.mobile }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold dark:text-slate-200">Financiële Informatie</h2>
                        <dl class="mt-2 space-y-1 text-sm text-gray-800 dark:text-slate-300">
                            <div v-if="customer.invoice_email">
                                <dt class="inline font-semibold">Factuur e-mail:</dt>
                                <dd class="inline ml-1">{{ customer.invoice_email }}</dd>
                            </div>
                            <div v-if="customer.quotes_email">
                                <dt class="inline font-semibold">Offerte e-mail:</dt>
                                <dd class="inline ml-1">{{ customer.quotes_email }}</dd>
                            </div>
                            <div v-if="customer.iban">
                                <dt class="inline font-semibold">IBAN:</dt>
                                <dd class="inline ml-1">{{ customer.iban }}</dd>
                            </div>
                            <div v-if="customer.vat_number">
                                <dt class="inline font-semibold">BTW-nummer:</dt>
                                <dd class="inline ml-1">{{ customer.vat_number }}</dd>
                            </div>
                            <div v-if="customer.chamber_of_commerce_number">
                                <dt class="inline font-semibold">KvK-nummer:</dt>
                                <dd class="inline ml-1">{{ customer.chamber_of_commerce_number }}</dd>
                            </div>
                        </dl>
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
                <CustomFieldsComponent v-if="customFields.length" model-type="customer" :model-id="customer.id"
                    :custom-fields="customFields" :can-edit="hasPermission('customfield.update')" class="mt-6" />
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
                                class="inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-900 ring-1 ring-inset ring-gray-200 dark:text-slate-200 dark:ring-slate-700">
                                {{ pt.name }}
                                <button type="button" @click="removeProductType(pt.id)"
                                    class="group relative -mr-1 h-3.5 w-3.5 rounded-sm hover:bg-gray-500/20">
                                    <span class="sr-only">Remove</span>
                                    <svg viewBox="0 0 14 14"
                                        class="h-3.5 w-3.5 text-gray-600/50 stroke-gray-600/75 group-hover:stroke-gray-600/75 dark:text-slate-400 dark:stroke-slate-400 dark:group-hover:stroke-slate-300">
                                        <path d="M4 4l6 6m0-6l-6 6" />
                                    </svg>
                                    <span class="absolute -inset-1" />
                                </button>
                            </span>
                            <button type="button" @click="resetFilters"
                                class="text-xs text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200">Reset</button>
                        </template>
                        <span v-else class="text-xs text-gray-500 dark:text-slate-400">Alle apparaat types</span>
                    </div>
                </div>
            </div>
            <div class="mt-4" v-if="canReadAssets && hasAssetsFiltered">
                <AssetListComponent :assets="assetsFiltered" />
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
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import { BuildingOffice2Icon, ClipboardDocumentListIcon, PlusCircleIcon, XCircleIcon, PencilSquareIcon } from '@heroicons/vue/24/outline';
import BoxComponent from '@/Components/BoxComponent.vue';
import AssetListComponent from '@/Components/AssetListComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { hasPermission } from '@/Utilities/Utilities';
import ServiceOrderRow from '@/Components/ServiceOrderRow.vue';
import EventTimelineComponent from '@/Components/Timeline/EventTimelineComponent.vue';
import AddAssetForm from '@/Components/AddAssetForm.vue';
import CustomFieldsComponent from '@/Components/CustomFieldsComponent.vue';

const props = defineProps({
    customer: {
        type: Object,
        required: true,
    },
    assets: {
        type: Array,
        required: true,
    },
    allCustomers: {
        type: Array,
        required: true,
    },
    allProducts: {
        type: Array,
        required: true,
    },
    customFields: {
        type: Array,
        default: () => [],
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

const clearBillingCustomer = () => {
    form.billing_customer_id = null;
    updateCustomer();
};

// Product type filtering ----------------------------------------------------
const selectedProductTypeIds = ref([]); // array of product_type ids

// Collect unique product types from the assets list
const productTypeOptions = computed(() => {
    const map = new Map();
    (props.assets || []).forEach(a => {
        const pt = a?.product?.product_type;
        if (pt && !map.has(pt.id)) map.set(pt.id, { id: pt.id, name: pt.name });
    });
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

// Filtering helper
const assetsFiltered = computed(() => {
    if (!selectedProductTypeIds.value.length) return props.assets;
    return (props.assets || []).filter(a => selectedProductTypeIds.value.includes(a?.product?.product_type?.id));
});

const hasAssetsFiltered = computed(() => assetsFiltered.value.length > 0);

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