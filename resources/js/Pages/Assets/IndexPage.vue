<template>
    <div class="p-4 bg-white rounded-md mb-3 dark:bg-slate-900 border border-gray-200 dark:border-slate-800 shadow-sm">
        <IndexHeaderComponent title="Machines" subtitle="Zoek en filter machines"
            search-placeholder="Zoek op merk, model, soort of klant" search-url="/assets" :paginator="assets"
            :add-label="canCreate ? 'Voeg machine toe' : ''" @add="() => canCreate && assetFormRef?.show()">
            <template #right>
                <div class="flex items-end w-full">
                    <div class="flex-grow">
                        <ComboBox :options="statusOptions" v-model="selectedStatus"
                            placeholder="Laat alleen status zien"
                            @update:modelValue="val => { updateLocalStorageStatus(val) }" />
                    </div>
                </div>
            </template>
        </IndexHeaderComponent>
    </div>
    <div class="mb-4" v-auto-animate v-if="canCreate">
        <CreateRecordForm ref="assetFormRef" external-trigger action="/assets" :fields="assetFields"
            add-button-label="Voeg machine toe" submit-label="Toevoegen" :before-submit="handleBeforeSubmit" />
    </div>

    <ModalDialog v-model:open="showChildModal" title="Vereiste serienummers" max-width-class="sm:max-w-lg">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Dit product vereist de volgende onderdelen. Voer voor elk onderdeel het serienummer in.
        </p>
        <div class="mt-4 space-y-4">
            <div v-for="(child, index) in pendingChildren" :key="index">
                <TextInput
                    v-model="child.serial_number"
                    :label="`${child.relation_name}: ${child.name}`"
                    placeholder="Serienummer"
                />
            </div>
        </div>
        <template #footer>
            <div class="flex gap-3 justify-end">
                <button
                    type="button"
                    class="rounded-md px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-slate-600 dark:hover:bg-slate-700"
                    @click="cancelChildModal"
                >
                    Annuleren
                </button>
                <button
                    type="button"
                    class="inline-flex justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    @click="confirmChildModal"
                >
                    Toevoegen
                </button>
            </div>
        </template>
    </ModalDialog>
    <BoxComponent padding="px-0 py-0 xl:px-0 xl:pt-0 xl:pb-0 sm:px-0 sm:pb-0 px-0 py-0">
        <ul role="list"
            class="divide-y divide-gray-100 dark:divide-gray-800 overflow-hidden bg-white dark:bg-slate-900 shadow-xs ring-1 ring-gray-900/5 sm:rounded-xl">
            <li v-for="asset in filteredAssets" :key="asset.id"
                class="relative flex flex-col md:flex-row justify-between gap-x-6 px-4 py-5 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors duration-300 sm:px-6">
                <div class="flex min-w-0 gap-x-4">
                    <img class="size-12 flex-none rounded-full bg-gray-50"
                        :src="asset.product.images.length > 0 ? `/storage/${asset.product.images[0].path}` : ''"
                        alt="" />
                    <div class="min-w-0 flex-auto">
                        <p class="text-sm/6 font-semibold text-gray-900 dark:text-gray-300">
                            <Link :href="`/assets/${asset.id}`">
                                <span class="absolute inset-x-0 -top-px bottom-0" />
                                {{ asset.product.brand.name }} {{ asset.product.model }}
                            </Link>
                        </p>
                        <p class="mt-1 flex text-xs/5 text-gray-500 dark:text-gray-400">
                            <Link :href="`/producttypes?search=${asset.product.product_type.name}`"
                                class="relative truncate underline">{{
                                    asset.product.product_type.name }}</Link>
                            &nbsp;bij&nbsp;
                            <Link :href="`/customers/${asset.customer.id}`" class="relative truncate underline">{{
                                asset.customer.name }}</Link>
                            &nbsp;in&nbsp;{{ asset.customer.city }}
                        </p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-x-4 pl-15 md:pl-0 mt-3 md:mt-0">
                    <div class="flex md:flex-col md:items-end">
                        <span v-if="asset.status === 'Actief'"
                            class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset">Actief</span>
                        <span v-else
                            class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-red-600/10 ring-inset">{{
                                asset.status }}</span>
                        <p v-if="asset.next_service_date"
                            class="mt-1 text-xs/5 text-gray-500 dark:text-gray-200 flex items-center ml-3 md:ml-0">
                            <CalendarDateRangeIcon class="inline-block size-5 text-gray-400 dark:text-gray-200 mr-2"
                                aria-hidden="true" />
                            <time :datetime="asset.next_service_date">{{ new
                                Date(asset.next_service_date).toLocaleDateString('nl-NL', {
                                    year: 'numeric',
                                    month: '2-digit',
                                    day: '2-digit',
                                }) }}</time>
                        </p>
                    </div>
                    <ChevronRightIcon
                        class="size-5 flex-none text-gray-400 absolute md:relative right-6 top-1/2 -translate-y-1/2 md:translate-y-0 md:top-auto md:right-auto md:ml-3"
                        aria-hidden="true" />
                </div>
            </li>
        </ul>
    </BoxComponent>
</template>

<script setup>
import { computed, ref } from 'vue';
import { ChevronRightIcon } from '@heroicons/vue/20/solid';
import { CalendarDateRangeIcon } from '@heroicons/vue/24/outline';
import { Link } from '@inertiajs/vue3';
import ComboBox from '@/Components/UI/ComboBox.vue';
import BoxComponent from '@/Components/BoxComponent.vue';
import CreateRecordForm from '@/Components/UI/CreateRecordForm.vue';
import IndexHeaderComponent from '@/Components/UI/IndexHeaderComponent.vue';
import ModalDialog from '@/Components/UI/ModalDialog.vue';
import TextInput from '@/Components/UI/TextInput.vue';
import { hasPermission } from '@/Utilities/Utilities';
const assetFormRef = ref(null)

const props = defineProps({
    assets: {
        type: Object,
        required: true,
    },
    allProducts: { type: Array, default: () => [] },
    allCustomers: { type: Array, default: () => [] },
    requiredProductablesByProduct: { type: Object, default: () => ({}) },
});

// no per-page search state needed; SearchComponent handles it

const selectedStatus = ref(Number(localStorage.getItem('selectedAssetStatus')) || 1);
const filteredAssets = computed(() => {
    if (selectedStatus.value === 1) {
        return props.assets.data;
    }
    return props.assets.data.filter(asset => {
        return asset.status === (selectedStatus.value === 2 ? 'Actief' : 'Niet actief');
    });
});

const statusOptions = [
    { id: 1, name: 'Alle' },
    { id: 2, name: 'Actief' },
    { id: 3, name: 'Niet actief' },
];

function updateLocalStorageStatus(val) {
    localStorage.setItem('selectedAssetStatus', val);
}

const assetFields = [
    { key: 'product_id', label: 'Product', type: 'combobox', options: props.allProducts, initialId: props.allProducts[0]?.id },
    { key: 'customer_id', label: 'Klant', type: 'combobox', options: props.allCustomers, initialId: props.allCustomers[0]?.id },
    { key: 'serial_number', label: 'Serie nr.', type: 'text' },
    { key: 'is_active', label: 'Actief', type: 'boolean', default: true },
]

const canCreate = computed(() => hasPermission('asset.create'))

const showChildModal = ref(false)
const pendingChildren = ref([])
let resolveChildModal = null

function handleBeforeSubmit(formData) {
    const required = props.requiredProductablesByProduct[formData.product_id]
    if (!required || required.length === 0) return {}

    pendingChildren.value = required.flatMap(item =>
        Array.from({ length: item.quantity }, () => ({
            productable_id: item.productable_id,
            name: item.name,
            relation_name: item.relation_name,
            serial_number: '',
        }))
    )
    showChildModal.value = true

    return new Promise((resolve) => {
        resolveChildModal = resolve
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