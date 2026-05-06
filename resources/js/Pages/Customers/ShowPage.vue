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
            <div class="mt-5 px-1 flex items-center justify-left">
                <h3 class="text-sm font-medium flex items-center">
                    <PuzzlePieceIcon class="size-5 text-gray-500 mr-2" />
                    Machines
                </h3>
                <button v-if="hasPermission('asset.create')" @click="addAssetDrawerOpen = true"
                    class="text-blue-600 hover:text-blue-800 pl-2 cursor-pointer"
                    v-tooltip="'Nieuwe machine toevoegen'">
                    <PlusIcon class="size-4" />
                </button>
            </div>
            <div class="mt-3 px-1">
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

        </template>

        <template #sidebar>
            <div class="space-y-4 md:ml-5 md:mt-0 ml-0 mt-5">

                <BoxComponent>
                    <div class="flex mb-4 border-b-1 border-gray-200 pb-2 justify-between items-center">
                        <div class="flex items-center">
                            <FolderIcon class="size-6 flex-none text-gray-500 mr-2" />
                            <h2 class="font-regular text-xl">Projecten</h2>
                        </div>
                        <PlusCircleIcon v-if="canCreateProject"
                            class="size-6 flex-none text-green-500 cursor-pointer hover:text-green-700"
                            @click="projectFormRef?.show()"
                            v-tooltip="`Maak een nieuw project aan voor ${customer.name}`" />
                    </div>

                    <CreateRecordForm ref="projectFormRef" external-trigger action="/projects" :fields="projectFields"
                        submit-label="Opslaan" />

                    <div v-if="!sortedProjects.length" class="text-sm text-gray-500 dark:text-slate-400">
                        Nog geen projecten
                    </div>

                    <div v-else class="space-y-3" v-auto-animate>
                        <div v-for="project in sortedProjects" :key="project.id"
                            class="rounded-md border border-gray-200 dark:border-slate-700/60 p-3 bg-white dark:bg-slate-900/40">
                            <div class="flex items-start justify-between gap-2">
                                <component :is="hasPermission('project.read') ? Link : 'span'"
                                    :href="`/projects/${project.id}`" :class="{
                                        'text-gray-800 dark:text-slate-200 font-medium': true,
                                        'underline hover:text-gray-600 dark:hover:text-slate-400': hasPermission('project.read')
                                    }">
                                    {{ project.title }}
                                </component>
                                <span class="text-xs" :class="projectStatusClass(project.status)">{{ project.status
                                    }}</span>
                            </div>

                            <div class="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                Start: {{ project.start_date ? nlDate(project.start_date) : 'Onbekend' }}
                                <span v-if="project.project_manager"> • Projectleider: {{ project.project_manager.name
                                    }}</span>
                            </div>

                            <h4
                                class="mt-3 mb-1 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-slate-400">
                                Werkbonnen
                            </h4>

                            <div class="mt-2 space-y-2" v-auto-animate>
                                <div v-if="!project.service_orders?.length"
                                    class="text-xs text-gray-500 dark:text-slate-400">
                                    Geen werkbonnen binnen dit project
                                </div>
                                <ServiceOrderRow v-for="serviceorder in project.service_orders" :key="serviceorder.id"
                                    :serviceorder="serviceorder" />
                            </div>
                        </div>
                    </div>
                </BoxComponent>

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
                    <div v-if="!serviceOrdersWithoutProject.length" class="text-sm text-gray-500 dark:text-slate-400">
                        Geen losse werkbonnen
                    </div>
                    <ServiceOrderRow v-for="serviceorder in serviceOrdersWithoutProject" v-bind:key="serviceorder.id"
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

    <DrawerComponent v-model="addAssetDrawerOpen" :title="`Nieuwe machine voor ${customer.name}`">
        <AddAssetForm :customerId="customer.id" :allProducts="allProducts" :bare="true"
            :required-productables-by-product="requiredProductablesByProduct" @created="addAssetDrawerOpen = false" />
    </DrawerComponent>
</template>

<script setup>
import TwoThirdsOneThird from '@/Layouts/TwoThirdsOneThird.vue';
import { BuildingOffice2Icon, ClipboardDocumentListIcon, PlusCircleIcon, PlusIcon, PuzzlePieceIcon, XCircleIcon, PencilSquareIcon, FolderIcon } from '@heroicons/vue/24/outline';
import DrawerComponent from '@/Components/UI/DrawerComponent.vue';
import BoxComponent from '@/Components/BoxComponent.vue';
import AssetListComponent from '@/Components/AssetListComponent.vue';
import ComboBox from '@/Components/UI/ComboBox.vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { hasPermission, nlDate, projectStatusClass } from '@/Utilities/Utilities';
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
    users: {
        type: Array,
        default: () => [],
    },
    statuses: {
        type: Array,
        default: () => [],
    },
    customFields: {
        type: Array,
        default: () => [],
    },
    requiredProductablesByProduct: {
        type: Object,
        default: () => ({}),
    },
});

const addAssetDrawerOpen = ref(false);

const form = useForm({
    ...props.customer,
    billing_customer_id: props.customer.billing_customer_id || null,
});

const newServiceOrderForm = useForm({
    customer_id: props.customer.id,
});

const projectFormRef = ref(null)

const canCreateServiceOrder = computed(() => hasPermission('serviceorder.create'));
const canCreateProject = computed(() => hasPermission('project.create'));

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

const sortedProjects = computed(() => {
    return (props.customer.projects || []).slice().sort((a, b) => {
        if (!a.start_date && !b.start_date) return 0
        if (!a.start_date) return 1
        if (!b.start_date) return -1
        return a.start_date.localeCompare(b.start_date)
    })
})

const serviceOrdersWithoutProject = computed(() => {
    return (props.customer.service_orders || []).filter(serviceorder => !serviceorder.project_id)
})

const projectFields = [
    { key: 'customer_id', type: 'number', default: props.customer.id, label: '', class: 'hidden' },
    { key: 'title', label: 'Titel', type: 'text', class: 'md:col-span-4' },
    { key: 'project_manager_id', label: 'Projectleider', type: 'combobox', options: props.users, initialId: props.users[0]?.id, class: 'md:col-span-4' },
    { key: 'status', label: 'Status', type: 'combobox', options: props.statuses, initialId: props.statuses[0]?.id, emitValue: true, class: 'md:col-span-4' },
    { key: 'start_date', label: 'Startdatum', type: 'date', class: 'md:col-span-4' },
    { key: 'end_date', label: 'Einddatum', type: 'date', class: 'md:col-span-4' },
    { key: 'description', label: 'Omschrijving', type: 'textarea', placeholder: 'Optioneel', class: 'md:col-span-4' },
]

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